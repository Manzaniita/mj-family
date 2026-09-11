<#
.SYNOPSIS
    Arma los .zip de los temas y plugins MJ cuya version haya cambiado, listos
    para subir a cualquier tienda WordPress.

.DESCRIPTION
    Recorre las carpetas declaradas en tools/releases.config.json, lee la
    version de cada paquete de su propio archivo (style.css en los temas,
    cabecera del plugin en los plugins) y la compara con los zip que ya estan
    en releases/:

        hay zip de esa version   -> no hace nada (no hay version nueva)
        no hay                   -> compila lo que haga falta y arma el zip

    En releases/ queda un unico zip por paquete, el de la ultima version: los
    de versiones anteriores se borran.

        releases/<slug>-<version>.zip   el paquete listo para subir
        releases/MANIFEST.md            que version quedo en cada zip, con SHA-256
        releases/manifest.json          lo mismo, para scripts

    Nada esta fijo en este script: rutas, exclusiones y compilaciones salen del
    archivo de configuracion.

.PARAMETER Force
    Rehace los zip aunque la version no haya cambiado. Util cuando se toco el
    codigo sin subir el numero de version.

.PARAMETER SkipBuild
    No compila las apps de React; empaqueta el dist/ que ya este en disco.

.PARAMETER Only
    Mira solo los paquetes indicados (por slug). Ej: -Only mj-appearance,mj-glossyhair

.PARAMETER Config
    Otro archivo de configuracion.

.EXAMPLE
    .\tools\build-releases.ps1
    .\tools\build-releases.ps1 -Force
    .\tools\build-releases.ps1 -Only mj-glossyhair
#>

[CmdletBinding()]
param(
    [switch] $SkipBuild,
    [switch] $Force,
    [string[]] $Only,
    [string] $Config
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$RepoRoot = Split-Path -Parent $PSScriptRoot
if (-not $Config) { $Config = Join-Path $PSScriptRoot 'releases.config.json' }

function Write-Step($text)  { Write-Host "`n== $text" -ForegroundColor Cyan }
function Write-Ok($text)    { Write-Host "   $text" -ForegroundColor Green }
function Write-Warn2($text) { Write-Host "   $text" -ForegroundColor Yellow }
function Write-Skip($text)  { Write-Host "   $text" -ForegroundColor DarkGray }

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

if (-not (Test-Path $Config)) { throw "No encuentro la configuracion: $Config" }
$cfg = Get-Content $Config -Raw -Encoding UTF8 | ConvertFrom-Json

$OutDir    = Join-Path $RepoRoot $cfg.output
$StageRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("mj-release-" + [guid]::NewGuid().ToString('N'))

# ---------------------------------------------------------------------------
# Version de un paquete
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

<#
    Los zip que quedaron de versiones anteriores de un paquete.

    Se exige que lo que sigue al slug empiece con un numero para no confundir el
    zip de "mj-appearance" con el de un futuro "mj-appearance-pro".
#>
function Get-OldZips {
    param([string] $Slug, [string] $KeepName)

    $rx = '^' + [regex]::Escape($Slug) + '-\d.*\.zip$'
    @(Get-ChildItem -Path $OutDir -Filter '*.zip' -File |
        Where-Object { $_.Name -match $rx -and $_.Name -ne $KeepName })
}

# ---------------------------------------------------------------------------
# 1. Descubrir los paquetes y ver cuales tienen version nueva
# ---------------------------------------------------------------------------
Write-Step 'Paquetes encontrados'
New-Item -ItemType Directory -Path $OutDir -Force | Out-Null

$packages = @()
foreach ($root in $cfg.roots) {
    $rootPath = Join-Path $RepoRoot $root.path
    if (-not (Test-Path $rootPath)) { continue }
    foreach ($dir in Get-ChildItem -Path $rootPath -Directory) {
        $info = Get-PackageInfo -Path $dir.FullName -Type $root.type
        if (-not $info) { Write-Warn2 "sin cabecera valida, se saltea: $($dir.Name)"; continue }
        if (-not $info.Version) { throw "$($info.Slug): no tiene version en su cabecera" }

        $zipName = "$($info.Slug)-$($info.Version).zip"
        $zipPath = Join-Path $OutDir $zipName
        $existe  = Test-Path $zipPath
        $elegido = (-not $Only) -or ($Only -contains $info.Slug)

        $info | Add-Member NoteProperty ZipName     $zipName
        $info | Add-Member NoteProperty ZipPath     $zipPath
        $info | Add-Member NoteProperty Existe      $existe
        $info | Add-Member NoteProperty Elegido     $elegido
        $info | Add-Member NoteProperty HayQueArmar ($elegido -and ((-not $existe) -or $Force))
        $packages += $info
    }
}
if (-not $packages) { throw 'No hay ningun paquete para empaquetar.' }

if ($Only) {
    $desconocidos = @($Only | Where-Object { $slug = $_; -not ($packages | Where-Object { $_.Slug -eq $slug }) })
    if ($desconocidos) { throw "No existe ningun paquete llamado: $($desconocidos -join ', ')" }
}

foreach ($p in $packages | Sort-Object Slug) {
    if (-not $p.Elegido)       { $estado = 'fuera de -Only' }
    elseif (-not $p.HayQueArmar) { $estado = 'ya estaba' }
    elseif ($p.Existe)         { $estado = 'se rehace (-Force)' }
    else                       { $estado = 'version nueva' }

    $linea = "{0,-26} {1,-10} {2}" -f $p.Slug, $p.Version, $estado
    if ($p.HayQueArmar) { Write-Host "   $linea" } else { Write-Skip $linea }
}

$porArmar = @($packages | Where-Object { $_.HayQueArmar })

# ---------------------------------------------------------------------------
# 2. Compilar solo las apps de los paquetes que se van a armar
#
# Cada app vive dentro del paquete al que pertenece, asi que el dueno sale de
# comparar rutas: no hace falta declararlo en la configuracion.
# ---------------------------------------------------------------------------
if (-not $porArmar) {
    Write-Step 'No hay versiones nuevas: no se compila nada'
} elseif ($SkipBuild) {
    Write-Step 'Compilacion omitida (-SkipBuild): se empaqueta el dist/ que haya en disco'
} else {
    Write-Step 'Compilando las apps de React'
    $sep = [System.IO.Path]::DirectorySeparatorChar
    foreach ($b in $cfg.builds) {
        $dir = [System.IO.Path]::GetFullPath((Join-Path $RepoRoot $b.path))

        $dueno = $packages |
            Where-Object { $dir.StartsWith($_.Path.TrimEnd($sep) + $sep, 'OrdinalIgnoreCase') } |
            Select-Object -First 1
        if ($dueno -and -not $dueno.HayQueArmar) {
            Write-Skip "sin version nueva, no se compila: $($b.name)"
            continue
        }
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
        if (-not (Test-Path $produced)) { throw "la compilacion no genero $($b.produces)" }
        Write-Ok "listo: $($b.produces)"
    }
}

# ---------------------------------------------------------------------------
# 3. Armar los zip que falten
# ---------------------------------------------------------------------------
if ($porArmar) {
    Write-Step 'Generando los zip'
    New-Item -ItemType Directory -Path $StageRoot -Force | Out-Null
    try {
        foreach ($p in $porArmar) {
            $stage = Join-Path $StageRoot $p.Slug
            Copy-Package -Source $p.Path -Destination (Join-Path $stage $p.Slug) -Exclude $cfg.exclude
            New-PackageZip -StageDir $stage -ZipPath $p.ZipPath

            $size = [math]::Round((Get-Item $p.ZipPath).Length / 1KB)
            Write-Ok ("{0,-34} {1,7} KB" -f $p.ZipName, $size)
        }
    } finally {
        if (Test-Path $StageRoot) { Remove-Item $StageRoot -Recurse -Force -ErrorAction SilentlyContinue }
    }
}

# ---------------------------------------------------------------------------
# 4. Borrar lo viejo
#
# Recien ahora, con el zip nuevo ya en disco: si algo falla antes, el de la
# version anterior sigue estando.
# ---------------------------------------------------------------------------
Write-Step 'Borrando lo viejo'
$borrados = 0
foreach ($p in $packages | Where-Object { $_.Elegido }) {
    if (-not (Test-Path $p.ZipPath)) { continue }
    foreach ($viejo in Get-OldZips -Slug $p.Slug -KeepName $p.ZipName) {
        Remove-Item $viejo.FullName -Force
        Write-Skip "borrado: $($viejo.Name)"
        $borrados++
    }
}

# Restos de la version anterior de este script, que armaba estas dos carpetas.
foreach ($resto in @('latest', 'bundles')) {
    $ruta = Join-Path $OutDir $resto
    if (Test-Path $ruta) {
        Remove-Item $ruta -Recurse -Force
        Write-Skip "borrada la carpeta $resto/ (ya no se usa)"
        $borrados++
    }
}

# Zip que no son de ningun paquete conocido: se avisan, no se tocan.
$huerfanos = @(Get-ChildItem -Path $OutDir -Filter '*.zip' -File | Where-Object {
    $nombre = $_.Name
    -not ($packages | Where-Object { $nombre -match ('^' + [regex]::Escape($_.Slug) + '-\d.*\.zip$') })
})
foreach ($h in $huerfanos) { Write-Warn2 "no es de ningun paquete, lo dejo: $($h.Name)" }
if (-not $borrados -and -not $huerfanos) { Write-Skip 'no habia nada para borrar' }

# ---------------------------------------------------------------------------
# 5. Manifiesto de lo que quedo en la carpeta
# ---------------------------------------------------------------------------
Write-Step 'Escribiendo el manifiesto'
$results = @()
foreach ($p in $packages | Sort-Object Slug) {
    if (-not (Test-Path $p.ZipPath)) {
        Write-Warn2 "sin zip en la carpeta, queda fuera del manifiesto: $($p.Slug) $($p.Version)"
        continue
    }
    $results += [pscustomobject]@{
        Slug = $p.Slug; Name = $p.Name; Type = $p.Type; Version = $p.Version
        Parent = $p.Parent; Zip = $p.ZipName
        SizeKB = [math]::Round((Get-Item $p.ZipPath).Length / 1KB)
        Sha256 = (Get-FileHash $p.ZipPath -Algorithm SHA256).Hash.ToLower()
    }
}

$fecha = Get-Date -Format 'yyyy-MM-dd HH:mm'
$md = @()
$md += '# Versiones publicadas'
$md += ''
$md += "Generado por ``tools/build-releases.ps1`` el $fecha."
$md += 'En `releases/` queda un solo zip por paquete: el de la versión que está'
$md += 'hoy en el código. Los de versiones anteriores se borran — las que ya se'
$md += 'publicaron siguen colgadas de su etiqueta en las Releases de GitHub.'
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

Write-Ok 'releases/MANIFEST.md y releases/manifest.json'

# ---------------------------------------------------------------------------
# 6. Que zip lleva cada tienda
#
# Solo se imprime: no se copia nada, estan todos en releases/.
# ---------------------------------------------------------------------------
Write-Step 'Que zip necesita cada tienda'
foreach ($name in $cfg.bundles.PSObject.Properties.Name) {
    Write-Host "   $name" -ForegroundColor White
    foreach ($slug in $cfg.bundles.$name.packages) {
        $r = $results | Where-Object { $_.Slug -eq $slug } | Select-Object -First 1
        if ($r) { Write-Skip "      $($r.Zip)" }
        else    { Write-Warn2 "      falta: $slug" }
    }
}

Write-Step 'Listo'
if ($porArmar) { Write-Host "   $($porArmar.Count) zip nuevos, $($results.Count) en total en $OutDir" -ForegroundColor Green }
else           { Write-Host "   sin versiones nuevas; $($results.Count) zip al dia en $OutDir" -ForegroundColor Green }
Write-Host '   El tema padre MJ Appearance se sube primero.' -ForegroundColor Green
