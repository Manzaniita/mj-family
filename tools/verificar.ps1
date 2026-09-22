<#
.SYNOPSIS
    Revisa todo el proyecto antes de publicar. Lo mismo que corre el CI.

.DESCRIPTION
    Seis controles, con el mismo alcance que el CI:

      1. Sintaxis de PHP (php -l) sobre todos los .php.
         Tolerancia cero. Un error acá es una pantalla blanca en una tienda.

      2. Compatibilidad con PHP 7.4, que es lo que declaran las cabeceras.
         Tolerancia cero. Sintaxis de PHP 8 revienta en un servidor viejo.

      3. Reglas de WordPress (seguridad y base de datos), comparadas contra
         tools/phpcs-baseline.json. Corta solo si aparece algo nuevo.

      4. Que compilen las dos apps (el tema y los turnos).
         Si el build falla, mejor enterarse acá que al ir a publicar.

      5. Copias compartidas presentes e idénticas a su fuente.
      6. Documentación, enlaces e instrucciones para Codex.

.PARAMETER Rapido
    Saltea el paso 4 (los builds), que es el que más tarda.

.PARAMETER Foto
    En vez de comparar, vuelve a sacar la foto de referencia de PHPCS.
    Se usa cuando se arregló deuda de verdad, nunca para tapar algo nuevo.

.EXAMPLE
    .\tools\verificar.ps1
    .\tools\verificar.ps1 -Rapido
    .\tools\verificar.ps1 -Foto
#>

[CmdletBinding()]
param(
    [switch]$Rapido,
    [switch]$Foto
)

# 'Continue' y no 'Stop': acá se llama a programas externos (php, phpcs, npm)
# que escriben avisos por la salida de error aunque hayan terminado bien.
# Con 'Stop', PowerShell 5.1 los toma como excepción y corta de más. El
# resultado de cada paso se mira por su código de salida, que es lo confiable.
$ErrorActionPreference = 'Continue'
$raiz = Split-Path -Parent $PSScriptRoot
Set-Location $raiz

$fallos = @()
$pasos  = 0

function Titulo($texto) {
    Write-Host ""
    Write-Host "== $texto " -ForegroundColor Cyan -NoNewline
    Write-Host ("=" * [Math]::Max(0, 60 - $texto.Length)) -ForegroundColor DarkGray
}

function Bien($texto) { Write-Host "   OK  $texto" -ForegroundColor Green }
function Mal($texto)  { Write-Host "  MAL  $texto" -ForegroundColor Red }
function Nota($texto) { Write-Host "       $texto" -ForegroundColor DarkGray }

# ---------------------------------------------------------------------------
# Encontrar PHP.
#
# Puede estar en el PATH, o instalado por winget sin haber reiniciado la
# consola todavía, que es el caso más común la primera vez.
# ---------------------------------------------------------------------------
function Buscar-Php {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }

    $paquetes = Join-Path $env:LOCALAPPDATA "Microsoft\WinGet\Packages"
    if (Test-Path $paquetes) {
        $encontrado = Get-ChildItem $paquetes -Filter php.exe -Recurse -ErrorAction SilentlyContinue |
                      Sort-Object FullName -Descending | Select-Object -First 1
        if ($encontrado) { return $encontrado.FullName }
    }
    return $null
}

$php = Buscar-Php
if (-not $php) {
    Write-Host ""
    Mal "No hay PHP en la máquina."
    Nota "Instalalo con:  winget install --id PHP.PHP.8.4 --scope user"
    Nota "Después abrí una consola nueva y volvé a correr esto."
    exit 1
}

$phpcs = Join-Path $raiz "vendor\squizlabs\php_codesniffer\bin\phpcs"
if (-not (Test-Path $phpcs)) {
    Write-Host ""
    Mal "Faltan las herramientas de verificación (vendor/)."
    Nota "Instalalas con:  composer install"
    Nota "Si no tenés composer: https://getcomposer.org/composer-stable.phar"
    exit 1
}

Write-Host ""
Write-Host "MJ Family - verificación" -ForegroundColor White
Nota "PHP: $php"

# ---------------------------------------------------------------------------
# 1. Sintaxis de PHP.
# ---------------------------------------------------------------------------
Titulo "1/6  Sintaxis de PHP"

# --cached y --others: lo que ya está versionado MÁS lo que todavía no se
# commiteó. Sin --others, un archivo recién creado no se revisaría, que es
# justo el que más chances tiene de estar roto. --exclude-standard respeta
# el .gitignore, así que no se mete en vendor/ ni en node_modules/.
$archivos = git ls-files --cached --others --exclude-standard '*.php'
$rotos = @()
foreach ($f in $archivos) {
    $salida = & $php -l $f
    if ($LASTEXITCODE -ne 0) {
        $rotos += $f
        Mal $f
        Nota ($salida -join "`n       ")
    }
}
$pasos++
if ($rotos.Count -eq 0) {
    Bien "$($archivos.Count) archivos, ninguno roto."
} else {
    $fallos += "Sintaxis de PHP: $($rotos.Count) archivos rotos"
}

# ---------------------------------------------------------------------------
# 2. Compatibilidad con PHP 7.4.
# ---------------------------------------------------------------------------
Titulo "2/6  Compatibilidad con PHP 7.4"

$salida = & $php $phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 7.4- `
    --extensions=php --ignore="*/vendor/*,*/node_modules/*,*/dist/*" `
    --report=summary --no-colors -q plugins themes 2>&1
$codigo = $LASTEXITCODE
$pasos++
if ($codigo -eq 0) {
    Bien "Nada que rompa en un servidor con PHP 7.4."
} else {
    $fallos += "Compatibilidad con PHP 7.4"
    Mal "Hay sintaxis que no existe en PHP 7.4:"
    $salida | ForEach-Object { Nota $_ }
}

# ---------------------------------------------------------------------------
# 3. Reglas de WordPress, contra la foto de referencia.
# ---------------------------------------------------------------------------
Titulo "3/6  Reglas de WordPress (seguridad y base de datos)"

$informe = Join-Path ([System.IO.Path]::GetTempPath()) "mj-phpcs.json"
& $php $phpcs --report=json --no-colors --report-file="$informe" -q 2>&1 | Out-Null

$pasos++
if ($Foto) {
    & $php (Join-Path $raiz "tools\phpcs-baseline.php") actualizar $informe
    Bien "Foto de referencia actualizada."
} else {
    & $php (Join-Path $raiz "tools\phpcs-baseline.php") comparar $informe
    if ($LASTEXITCODE -eq 0) {
        Bien "Sin avisos nuevos."
    } else {
        $fallos += "Avisos nuevos de PHPCS"
    }
}

# ---------------------------------------------------------------------------
# 4. Que compilen las dos apps.
# ---------------------------------------------------------------------------
Titulo "4/6  Compilación de las apps"

if ($Rapido) {
    Nota "Salteado (-Rapido)."
} else {
    $apps = @(
        @{ Nombre = "Tema MJ Appearance"; Ruta = "themes\mj-appearance\react-app" },
        @{ Nombre = "App de turnos";      Ruta = "plugins\mj-turnos-online\app" }
    )
    foreach ($app in $apps) {
        $ruta = Join-Path $raiz $app.Ruta
        if (-not (Test-Path (Join-Path $ruta "package.json"))) {
            Nota "$($app.Nombre): no está, se saltea."
            continue
        }
        Push-Location $ruta
        try {
            # La salida de npm se redirige con cmd y no con el "2>&1" de
            # PowerShell: en 5.1, redirigir la salida de error de un programa
            # externo la convierte en excepción aunque el programa haya
            # terminado bien. Con cmd, PowerShell no toca los streams.
            $log = Join-Path ([System.IO.Path]::GetTempPath()) "mj-build.log"

            if (-not (Test-Path "node_modules")) {
                Nota "$($app.Nombre): instalando dependencias..."
                cmd /c "npm ci --no-audit --no-fund > `"$log`" 2>&1"
                if ($LASTEXITCODE -ne 0) {
                    $fallos += "Dependencias: $($app.Nombre)"
                    Mal "No se pudieron instalar las dependencias de $($app.Nombre)."
                    if (Test-Path $log) { Get-Content $log -Tail 15 | ForEach-Object { Nota $_ } }
                    continue
                }
            }

            cmd /c "npm run build > `"$log`" 2>&1"
            if ($LASTEXITCODE -eq 0) {
                Bien $app.Nombre
            } else {
                $fallos += "Build: $($app.Nombre)"
                Mal $app.Nombre
                if (Test-Path $log) {
                    Get-Content $log -Tail 15 | ForEach-Object { Nota $_ }
                }
            }
        } finally {
            Pop-Location
        }
    }
}
$pasos++

# ---------------------------------------------------------------------------
# 5. Copias compartidas: usar la misma lista de consumidores que al repartir.
# ---------------------------------------------------------------------------
Titulo "5/6  Copias de shared/"
& (Join-Path $raiz "tools\sincronizar-compartido.ps1") -Verificar
$pasos++
if ($LASTEXITCODE -ne 0) { $fallos += "Copias compartidas desincronizadas" }

# ---------------------------------------------------------------------------
# 6. Documentación y guías de agentes.
# ---------------------------------------------------------------------------
Titulo "6/6  Documentación e instrucciones"
& $php (Join-Path $raiz "tools\verificar-documentacion.php")
$pasos++
if ($LASTEXITCODE -ne 0) { $fallos += "Documentación e instrucciones" }

# ---------------------------------------------------------------------------
# Resultado.
# ---------------------------------------------------------------------------
Write-Host ""
Write-Host ("-" * 64) -ForegroundColor DarkGray
if ($fallos.Count -eq 0) {
    Write-Host " TODO EN ORDEN" -ForegroundColor Green
    Write-Host ""
    exit 0
}

Write-Host " NO PASA" -ForegroundColor Red
foreach ($f in $fallos) { Write-Host "   - $f" -ForegroundColor Red }
Write-Host ""
exit 1
