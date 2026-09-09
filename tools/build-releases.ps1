<#
.SYNOPSIS
    Arma los .zip de todos los temas y plugins MJ, listos para subir a cualquier
    tienda WordPress.

.DESCRIPTION
    Recorre las carpetas declaradas en tools/releases.config.json, compila las
    apps que lo necesiten, lee la versión de cada paquete de su propio archivo
    (style.css en los temas, cabecera del plugin en los plugins) y genera:

        releases/<slug>-<version>.zip     una copia por versión (nunca se pisan)
        releases/latest/<slug>.zip        la última, con nombre fijo
        releases/bundles/<tienda>/        los zips que necesita cada tienda
        releases/MANIFEST.md              qué versión quedó en cada zip, con SHA-256

    Nada está fijo en este script: rutas, exclusiones, compilaciones y bundles
    salen del archivo de configuración.

.PARAMETER SkipBuild
    No compila las apps de React; empaqueta el dist/ que ya esté en disco.

.PARAMETER Only
    Empaqueta solo los paquetes indicados (por slug). Ej: -Only mj-appearance,mj-glossyhair

.PARAMETER Config
    Otro archivo de configuración.

.EXAMPLE
    .\tools\build-releases.ps1
    .\tools\build-releases.ps1 -SkipBuild
    .\tools\build-releases.ps1 -Only mj-glossyhair
#>

[CmdletBinding()]
param(
    [switch] $SkipBuild,
    [string[]] $Only,
    [string] $Config
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$RepoRoot = Split-Path -Parent $PSScriptRoot
if (-not $Config) { $Config = Join-Path $PSScriptRoot 'releases.config.json' }

function Write-Step($text) { Write-Host "`n== $text" -ForegroundColor Cyan }
function Write-Ok($text)   { Write-Host "   $text" -ForegroundColor Green }
function Write-Warn2($text) { Write-Host "   $text" -ForegroundColor Yellow }

<#
    Ejecuta un comando externo (npm) y falla solo si su codigo de salida no es 0.

    En Windows PowerShell 5.1 no se puede redirigir la salida de error de un
    programa externo con 2>&1: cada linea vuelve envuelta en un error y, con
    $ErrorActionPreference = 'Stop', aborta el script aunque el comando haya
    terminado bien. npm escribe su progreso por esa salida, asi que se la deja
    pasar y se mira unicamente el codigo de salida.
#>
function Invoke-Native {
    param([string] $Command, [string] $What)

    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & cmd.exe /c "$Command 2>&1" | Out-Null
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    if ($code -ne 0) { throw "$What termino con codigo $code : $Command" }
}

if (-not (Test-Path $Config)) { throw "No encuentro la configuración: $Config" }
$cfg = Get-Content $Config -Raw -Encoding UTF8 | ConvertFrom-Json

$OutDir     = Join-Path $RepoRoot $cfg.output
$LatestDir  = Join-Path $OutDir 'latest'
$BundlesDir = Join-Path $OutDir 'bundles'
$StageRoot  = Join-Path ([System.IO.Path]::GetTempPath()) ("mj-release-" + [guid]::NewGuid().ToString('N'))

# ---------------------------------------------------------------------------
# Versión de un paquete
#
# Los temas la declaran en style.css y los plugins en la cabecera de su archivo
# principal. Se busca en los .php de primer nivel para no depender del nombre
# del archivo.
# ---------------------------------------------------------------------------
function Get-PackageInfo {
    param([string] $Path, [string] $Type)

    $slug = Split-Path $Path -Leaf
    $name = $slug
    $version = $null

    if ($Type -eq 'theme') {
        $style = Join-Path $Path 'style.css'
        if (-not (Test-Path $style)) { return $null }
        $header = Get-Content $style -Raw -Encoding UTF8
        if ($header -match '(?m)^\s*Theme Name:\s*(.+?)\s*$') { $name = $Matches[1] }
        if ($header -match '(?m)^\s*Version:\s*(.+?)\s*$')    { $version = $Matches[1] }
        $parent = $null
        if ($header -match '(?m)^\s*Template:\s*(.+?)\s*$')   { $parent = $Matches[1] }
        return [pscustomobject]@{
            Slug = $slug; Name = $name; Version = $version
            Type = $Type; Path = $Path; Parent = $parent
        }
    }

    foreach ($php in Get-ChildItem -Path $Path -Filter *.php -File) {
        $head = Get-Content $php.FullName -TotalCount 40 -Encoding UTF8 | Out-String
        if ($head -match '(?m)^\s*\*?\s*Plugin Name:\s*(.+?)\s*$') {
            $name = $Matches[1]
            if ($head -match '(?m)^\s*\*?\s*Version:\s*(.+?)\s*$') { $version = $Matches[1] }
            return [pscustomobject]@{
                Slug = $slug; Name = $name; Version = $version
                Type = $Type; Path = $Path; Parent = $null
            }
        }
    }
    return $null
}

# ---------------------------------------------------------------------------
# Copia del paquete a una carpeta temporal, sin lo que no va al zip
# ---------------------------------------------------------------------------
function Copy-Package {
    param([string] $Source, [string] $Destination, [string[]] $Exclude)

    New-Item -ItemType Directory -Path $Destination -Force | Out-Null

    $names    = @($Exclude | Where-Object { $_ -notmatch '\*' })
    $patterns = @($Exclude | Where-Object { $_ -match '\*' })

    Get-ChildItem -Path $Source -Force | ForEach-Object {
        $item = $_
        if ($names -contains $item.Name) { return }
        foreach ($p in $patterns) { if ($item.Name -like $p) { return } }

        $target = Join-Path $Destination $item.Name
        if ($item.PSIsContainer) {
            Copy-Package -Source $item.FullName -Destination $target -Exclude $Exclude
        } else {
            Copy-Item -Path $item.FullName -Destination $target -Force
        }
    }
}

<#
    Zip con separadores "/" en los nombres de archivo.

    CreateFromDirectory() en Windows PowerShell usa el separador de Windows,
    que el formato ZIP no admite: al descomprimir en un servidor Linux,
    WordPress crea UN archivo con el separador dentro del nombre en vez de la
    carpeta con su contenido, y el plugin no arranca. Por eso las entradas se
    agregan una por una, normalizando el separador a "/".
#>
function New-PackageZip {
    param([string] $StageDir, [string] $ZipPath)

    if (Test-Path $ZipPath) { Remove-Item $ZipPath -Force }
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem

    $sep  = [System.IO.Path]::DirectorySeparatorChar
    $base = (Resolve-Path $StageDir).Path.TrimEnd($sep) + $sep
    $zip  = [System.IO.Compression.ZipFile]::Open($ZipPath, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($file in Get-ChildItem -Path $StageDir -Recurse -File -Force | Sort-Object FullName) {
            $relative = $file.FullName.Substring($base.Length).Replace($sep, '/')
            [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $zip, $file.FullName, $relative,
                [System.IO.Compression.CompressionLevel]::Optimal
            )
        }
    } finally {
        $zip.Dispose()
    }
}

# ---------------------------------------------------------------------------
# 1. Compilar las apps
# ---------------------------------------------------------------------------
if ($SkipBuild) {
    Write-Step 'Compilación omitida (-SkipBuild): se empaqueta el dist/ que haya en disco'
} else {
    Write-Step 'Compilando las apps de React'
    foreach ($b in $cfg.builds) {
        $dir = Join-Path $RepoRoot $b.path
        if (-not (Test-Path $dir)) { Write-Warn2 "salteada (no existe): $($b.path)"; continue }
        Write-Host "   $($b.name)"

        Push-Location $dir
        try {
            if (-not (Test-Path (Join-Path $dir 'node_modules'))) {
                Write-Host '     instalando dependencias...'
                Invoke-Native -Command $b.install -What "la instalacion de $($b.name)"
            }
            Invoke-Native -Command $b.build -What "la compilacion de $($b.name)"
        } finally { Pop-Location }

        $produced = Join-Path $RepoRoot $b.produces
        if (-not (Test-Path $produced)) { throw "la compilación no generó $($b.produces)" }
        Write-Ok "listo: $($b.produces)"
    }
}

# ---------------------------------------------------------------------------
# 2. Descubrir los paquetes
# ---------------------------------------------------------------------------
Write-Step 'Paquetes encontrados'
$packages = @()
foreach ($root in $cfg.roots) {
    $rootPath = Join-Path $RepoRoot $root.path
    if (-not (Test-Path $rootPath)) { continue }
    foreach ($dir in Get-ChildItem -Path $rootPath -Directory) {
        $info = Get-PackageInfo -Path $dir.FullName -Type $root.type
        if (-not $info) { Write-Warn2 "sin cabecera válida, se saltea: $($dir.Name)"; continue }
        if (-not $info.Version) { throw "$($info.Slug): no tiene versión en su cabecera" }
        if ($Only -and ($Only -notcontains $info.Slug)) { continue }
        $packages += $info
    }
}
if (-not $packages) { throw 'No hay ningún paquete para empaquetar.' }
foreach ($p in $packages) { Write-Host ("   {0,-26} {1,-10} {2}" -f $p.Slug, $p.Version, $p.Name) }

# ---------------------------------------------------------------------------
# 3. Empaquetar
# ---------------------------------------------------------------------------
Write-Step 'Generando los zip'
New-Item -ItemType Directory -Path $OutDir -Force | Out-Null
New-Item -ItemType Directory -Path $LatestDir -Force | Out-Null
New-Item -ItemType Directory -Path $StageRoot -Force | Out-Null

$results = @()
try {
    foreach ($p in $packages) {
        $stage = Join-Path $StageRoot $p.Slug
        Copy-Package -Source $p.Path -Destination (Join-Path $stage $p.Slug) -Exclude $cfg.exclude

        $zipName = "$($p.Slug)-$($p.Version).zip"
        $zipPath = Join-Path $OutDir $zipName
        New-PackageZip -StageDir $stage -ZipPath $zipPath

        Copy-Item $zipPath (Join-Path $LatestDir "$($p.Slug).zip") -Force

        $hash = (Get-FileHash $zipPath -Algorithm SHA256).Hash.ToLower()
        $size = [math]::Round((Get-Item $zipPath).Length / 1KB)
        $results += [pscustomobject]@{
            Slug = $p.Slug; Name = $p.Name; Type = $p.Type; Version = $p.Version
            Parent = $p.Parent; Zip = $zipName; SizeKB = $size; Sha256 = $hash
        }
        Write-Ok ("{0,-34} {1,7} KB" -f $zipName, $size)
    }

    # -----------------------------------------------------------------------
    # 4. Bundles por tienda
    # -----------------------------------------------------------------------
    Write-Step 'Armando los conjuntos por tienda'
    if (Test-Path $BundlesDir) { Remove-Item $BundlesDir -Recurse -Force }
    New-Item -ItemType Directory -Path $BundlesDir -Force | Out-Null

    foreach ($name in $cfg.bundles.PSObject.Properties.Name) {
        $bundle = $cfg.bundles.$name
        $dir = Join-Path $BundlesDir $name
        New-Item -ItemType Directory -Path $dir -Force | Out-Null

        $lines = @("# $($bundle.label)", '', 'Subí los zip de esta carpeta a WordPress en este orden:', '')
        $n = 0
        $missing = @()
        foreach ($slug in $bundle.packages) {
            $r = $results | Where-Object { $_.Slug -eq $slug } | Select-Object -First 1
            if (-not $r) { $missing += $slug; continue }
            Copy-Item (Join-Path $OutDir $r.Zip) (Join-Path $dir $r.Zip) -Force
            $n++
            if ($r.Type -eq 'theme') { $donde = 'Apariencia -> Temas -> Anadir -> Subir tema' }
            else { $donde = 'Plugins -> Anadir -> Subir plugin' }
            $lines += "$n. **$($r.Name)** `($($r.Zip))` — $donde"
        }
        if ($missing) { $lines += ''; $lines += "> Faltan (no se empaquetaron en esta corrida): $($missing -join ', ')" }
        $lines += ''
        $lines += 'El tema hijo necesita que **MJ Appearance** ya esté subido: sin el padre, WordPress lo marca como roto.'
        $lines += 'Después de activar el tema, la configuración está en **Apariencia → MJ Appearance**.'
        Set-Content -Path (Join-Path $dir 'LEEME.md') -Value ($lines -join "`r`n") -Encoding utf8
        Write-Ok ("{0,-14} {1} zip" -f $name, $n)
    }

    # -----------------------------------------------------------------------
    # 5. Manifiesto
    # -----------------------------------------------------------------------
    Write-Step 'Escribiendo el manifiesto'
    $fecha = Get-Date -Format 'yyyy-MM-dd HH:mm'
    $md = @()
    $md += '# Versiones publicadas'
    $md += ''
    $md += "Generado por ``tools/build-releases.ps1`` el $fecha."
    $md += 'Cada zip se guarda con su número de versión y nunca se sobrescribe:'
    $md += 'las versiones anteriores siguen en esta carpeta.'
    $md += ''
    $md += '## Temas'
    $md += ''
    $md += '| Tema | Versión | Archivo | Tamaño | SHA-256 |'
    $md += '| --- | --- | --- | ---: | --- |'
    foreach ($r in $results | Where-Object { $_.Type -eq 'theme' } | Sort-Object Slug) {
        $md += "| $($r.Name) | $($r.Version) | ``$($r.Zip)`` | $($r.SizeKB) KB | ``$($r.Sha256.Substring(0,16))…`` |"
    }
    $md += ''
    $md += '## Plugins'
    $md += ''
    $md += '| Plugin | Versión | Archivo | Tamaño | SHA-256 |'
    $md += '| --- | --- | --- | ---: | --- |'
    foreach ($r in $results | Where-Object { $_.Type -eq 'plugin' } | Sort-Object Slug) {
        $md += "| $($r.Name) | $($r.Version) | ``$($r.Zip)`` | $($r.SizeKB) KB | ``$($r.Sha256.Substring(0,16))…`` |"
    }
    $md += ''
    $md += '## SHA-256 completos'
    $md += ''
    $md += '```'
    foreach ($r in $results | Sort-Object Slug) { $md += "$($r.Sha256)  $($r.Zip)" }
    $md += '```'
    Set-Content -Path (Join-Path $OutDir 'MANIFEST.md') -Value ($md -join "`r`n") -Encoding utf8

    $results | Sort-Object Slug | ConvertTo-Json -Depth 4 |
        Set-Content -Path (Join-Path $OutDir 'manifest.json') -Encoding utf8

    Write-Ok "releases/MANIFEST.md y releases/manifest.json"
}
finally {
    if (Test-Path $StageRoot) { Remove-Item $StageRoot -Recurse -Force -ErrorAction SilentlyContinue }
}

Write-Step 'Listo'
Write-Host "   $($results.Count) paquetes en $OutDir" -ForegroundColor Green
Write-Host "   Para subir una tienda entera: releases\bundles\<tienda>\" -ForegroundColor Green
