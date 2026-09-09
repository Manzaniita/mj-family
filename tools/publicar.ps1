<#
.SYNOPSIS
    Publica una versión: compila, arma los zip, guarda los cambios en git y
    sube todo a GitHub con su etiqueta y sus paquetes.

.DESCRIPTION
    Es el paso único para dejar el repositorio y las tiendas sincronizados.
    Hace, en orden:

        1. Comprueba que el repositorio esté en condiciones (rama, remoto, gh).
        2. Corre tools/build-releases.ps1 (compila React y arma los zip).
        3. Comitea lo que haya cambiado con el mensaje que le pases.
        4. Crea la etiqueta de la versión.
        5. Sube rama y etiqueta.
        6. Publica los zip como Release de GitHub, colgados de esa etiqueta.

    Antes de tocar nada muestra qué va a hacer y pide confirmación.

.PARAMETER Mensaje
    Texto del commit. Ej: "GlossyHair 1.2.0: nueva paleta"

.PARAMETER Version
    Nombre de la etiqueta. Por defecto, la fecha de hoy (vAAAA.MM.DD).

.PARAMETER Notas
    Archivo .md con las notas de la publicación. Si no se pasa, se arma una
    lista automática con la versión de cada paquete.

.PARAMETER SkipBuild
    No recompila las apps de React; usa el dist/ que ya esté en disco.

.PARAMETER SinConfirmar
    No pregunta antes de subir.

.EXAMPLE
    .\tools\publicar.ps1 -Mensaje "GlossyHair 1.2.0: nueva paleta"
    .\tools\publicar.ps1 -Mensaje "Arregla el carrito" -Version v2026.10.01
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $Mensaje,
    [string] $Version,
    [string] $Notas,
    [switch] $SkipBuild,
    [switch] $SinConfirmar
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$RepoRoot = Split-Path -Parent $PSScriptRoot
Push-Location $RepoRoot

function Write-Step($text) { Write-Host "`n== $text" -ForegroundColor Cyan }
function Write-Ok($text)   { Write-Host "   $text" -ForegroundColor Green }

<#
    Igual que en build-releases.ps1: Windows PowerShell 5.1 convierte cada
    línea de la salida de error de un programa externo en un error propio y,
    con ErrorActionPreference = Stop, aborta aunque el comando haya andado
    bien. Se mira únicamente el código de salida.
#>
function Invoke-Native {
    param([string] $Command, [string] $What, [switch] $Silencioso)

    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        if ($Silencioso) { & cmd.exe /c "$Command 2>&1" | Out-Null }
        else             { & cmd.exe /c "$Command 2>&1" }
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    if ($code -ne 0) { throw "$What termino con codigo $code : $Command" }
}

try {
    # -----------------------------------------------------------------------
    # 1. Comprobaciones previas
    # -----------------------------------------------------------------------
    Write-Step 'Comprobando el repositorio'

    if (-not (Get-Command git -ErrorAction SilentlyContinue)) { throw 'No encuentro git.' }
    if (-not (Get-Command gh  -ErrorAction SilentlyContinue)) { throw 'No encuentro gh (GitHub CLI). Instalalo desde https://cli.github.com' }

    Invoke-Native -Command 'gh auth status' -What 'la comprobacion de la sesion de GitHub' -Silencioso
    Write-Ok 'sesion de GitHub activa'

    $rama = (& git rev-parse --abbrev-ref HEAD).Trim()
    Write-Ok "rama: $rama"

    $remoto = (& git remote 2>$null)
    if (-not $remoto) { throw 'El repositorio no tiene remoto configurado (falta origin).' }

    if (-not $Version) { $Version = 'v' + (Get-Date -Format 'yyyy.MM.dd') }
    if (& git tag --list $Version) {
        throw "La etiqueta $Version ya existe. Pasá otra con -Version (por ejemplo ${Version}b)."
    }
    Write-Ok "etiqueta a crear: $Version"

    # -----------------------------------------------------------------------
    # 2. Compilar y empaquetar
    # -----------------------------------------------------------------------
    # Si el armado falla, build-releases.ps1 lanza el error y corta acá mismo:
    # no se comitea ni se sube nada a medio hacer.
    & (Join-Path $PSScriptRoot 'build-releases.ps1') -SkipBuild:$SkipBuild

    $zips = @(Get-ChildItem -Path (Join-Path $RepoRoot 'releases') -Filter *.zip -File)
    if (-not $zips) { throw 'No se generó ningún zip.' }

    # -----------------------------------------------------------------------
    # 3. Confirmación
    # -----------------------------------------------------------------------
    Write-Step 'Se va a publicar'
    Write-Host "   commit:    $Mensaje"
    Write-Host "   etiqueta:  $Version"
    Write-Host "   rama:      $rama"
    Write-Host "   paquetes:  $($zips.Count) zip"
    $cambios = @(& git status --porcelain)
    Write-Host "   cambios:   $($cambios.Count) archivos"

    if (-not $SinConfirmar) {
        $r = Read-Host "`n   ¿Publicar? (s/N)"
        if ($r -notmatch '^[sSyY]') { Write-Host '   Cancelado.' -ForegroundColor Yellow; return }
    }

    # -----------------------------------------------------------------------
    # 4. Commit, etiqueta y subida
    # -----------------------------------------------------------------------
    Write-Step 'Guardando en git'
    if ($cambios.Count -gt 0) {
        & git add -A
        & git commit -m $Mensaje | Out-Null
        Write-Ok 'commit creado'
    } else {
        Write-Ok 'no había cambios que comitear'
    }

    & git tag -a $Version -m $Mensaje
    Write-Ok "etiqueta $Version creada"

    Write-Step 'Subiendo a GitHub'
    Invoke-Native -Command "git push origin $rama" -What 'el envio de la rama' -Silencioso
    Invoke-Native -Command "git push origin $Version" -What 'el envio de la etiqueta' -Silencioso
    Write-Ok 'rama y etiqueta subidas'

    # -----------------------------------------------------------------------
    # 5. Release con los zip
    # -----------------------------------------------------------------------
    Write-Step 'Publicando los paquetes'

    $notasPath = $Notas
    $temporal  = $null
    if (-not $notasPath) {
        # Sin notas propias: se arma la lista de versiones desde el manifiesto.
        $manifest = Join-Path $RepoRoot 'releases/manifest.json'
        $lineas = @($Mensaje, '', '## Versiones de esta publicación', '', '| Paquete | Versión |', '| --- | --- |')
        if (Test-Path $manifest) {
            foreach ($p in (Get-Content $manifest -Raw -Encoding UTF8 | ConvertFrom-Json)) {
                $lineas += "| $($p.Name) | $($p.Version) |"
            }
        }
        $lineas += ''
        $lineas += 'El tema padre **MJ Appearance** se sube primero: un tema hijo sin su padre aparece como "tema roto" en WordPress.'
        $temporal  = Join-Path ([System.IO.Path]::GetTempPath()) ("mj-notas-" + [guid]::NewGuid().ToString('N') + '.md')
        Set-Content -Path $temporal -Value ($lineas -join "`r`n") -Encoding utf8
        $notasPath = $temporal
    }

    try {
        $archivos = ($zips | ForEach-Object { '"' + $_.FullName + '"' }) -join ' '
        Invoke-Native -Command "gh release create $Version $archivos --title `"$Version`" --notes-file `"$notasPath`"" -What 'la publicacion del release' -Silencioso
    } finally {
        if ($temporal -and (Test-Path $temporal)) { Remove-Item $temporal -Force }
    }

    $url = (& gh repo view --json url --jq .url).Trim()
    Write-Step 'Listo'
    Write-Host "   $url/releases/tag/$Version" -ForegroundColor Green
}
finally {
    Pop-Location
}
