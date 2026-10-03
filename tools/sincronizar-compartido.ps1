<#
.SYNOPSIS
    Reparte los archivos compartidos de shared/ a cada plugin que los usa.

.DESCRIPTION
    EL PROBLEMA QUE RESUELVE

    Hay código que sirve a varios plugins —hoy el cuaderno de errores, mañana
    el formato de precio o la validación de código postal—. Ese código podría
    vivir en un plugin aparte del que todos dependan, pero eso rompería la
    regla de la casa: cada plugin tiene que poder desactivarse solo, sin
    arrastrar a los demás.

    Entonces se hace al revés: el archivo se escribe una vez en shared/ y se
    copia adentro de cada plugin que lo necesita. Cada plugin sigue siendo
    autosuficiente, y la clase se protege con class_exists, así que si hay dos
    copias cargadas, la segunda no pisa a la primera.

    El precio de esto es que las copias se pueden desincronizar. Por eso están
    este script, que las reparte, y el CI, que verifica que estén iguales.

    LO QUE HAY QUE RECORDAR

    Editar siempre shared/. Las copias que están dentro de los plugins se
    pisan sin preguntar.

.PARAMETER Verificar
    No copia nada: solo avisa si alguna copia quedó distinta de la maestra.
    Es lo que corre el CI. Sale con código 1 si hay diferencias.

.EXAMPLE
    .\tools\sincronizar-compartido.ps1
    .\tools\sincronizar-compartido.ps1 -Verificar
#>

[CmdletBinding()]
param(
    [switch]$Verificar
)

$ErrorActionPreference = 'Continue'
$raiz = Split-Path -Parent $PSScriptRoot
Set-Location $raiz

# ---------------------------------------------------------------------------
# Qué se reparte y a quién.
#
# Un plugin entra en esta lista cuando de verdad usa el archivo. No se reparte
# a todos "por las dudas": código que nadie llama es peso muerto en el zip.
# ---------------------------------------------------------------------------
$compartidos = @(
    @{
        Archivo = "class-mj-log.php"
        Plugins = @(
            "mj-core",
            "mj-dolar",
            "mj-envios",
            "mj-erp",
            "mj-instagram",
            "mj-medios-pago"
        )
    },
    @{
        # El menu unico de wp-admin: lo necesita todo plugin con pantalla propia.
        Archivo = "class-mj-menu.php"
        Plugins = @(
            "mj-colegas",
            "mj-core",
            "mj-dolar",
            "mj-envios",
            "mj-erp",
            "mj-fidelidad",
            "mj-fotos-rapidas",
            "mj-instagram",
            "mj-medios-pago",
            "mj-optimizador-imagenes",
            "mj-popup",
            "mj-turnos-online"
        )
    }
)

function Nota($t) { Write-Host "       $t" -ForegroundColor DarkGray }
function Bien($t) { Write-Host "   OK  $t" -ForegroundColor Green }
function Mal($t)  { Write-Host "  MAL  $t" -ForegroundColor Red }

# ---------------------------------------------------------------------------
# Cada plugin guarda sus clases en includes/ o en inc/, según cuál sea.
# Se respeta la que ya usa en vez de imponer una.
# ---------------------------------------------------------------------------
function Carpeta-Destino($plugin) {
    $base = Join-Path $raiz "plugins\$plugin"
    if (-not (Test-Path $base)) { return $null }

    foreach ($nombre in @("includes", "inc")) {
        if (Test-Path (Join-Path $base $nombre)) {
            return Join-Path $base "$nombre\compartido"
        }
    }
    return Join-Path $base "compartido"
}

Write-Host ""
if ($Verificar) {
    Write-Host "Verificando archivos compartidos" -ForegroundColor White
} else {
    Write-Host "Repartiendo archivos compartidos" -ForegroundColor White
}
Write-Host ""

$desincronizados = @()
$copiados        = 0

foreach ($item in $compartidos) {
    $maestra = Join-Path $raiz "shared\$($item.Archivo)"
    if (-not (Test-Path $maestra)) {
        Mal "Falta la copia maestra: shared/$($item.Archivo)"
        exit 1
    }

    $hashMaestra = (Get-FileHash $maestra -Algorithm SHA256).Hash
    Write-Host " $($item.Archivo)" -ForegroundColor Cyan
    Nota "maestra: $($hashMaestra.Substring(0,12))"

    foreach ($plugin in $item.Plugins) {
        $carpeta = Carpeta-Destino $plugin
        if (-not $carpeta) {
            Mal "$plugin no existe."
            $desincronizados += "$plugin (no existe)"
            continue
        }

        $destino = Join-Path $carpeta $item.Archivo
        $igual   = (Test-Path $destino) -and ((Get-FileHash $destino -Algorithm SHA256).Hash -eq $hashMaestra)

        if ($igual) {
            Bien "$plugin"
            continue
        }

        if ($Verificar) {
            if (Test-Path $destino) {
                Mal "$plugin — la copia quedó distinta de la maestra"
                $desincronizados += "$plugin/$($item.Archivo)"
            } else {
                Mal "$plugin — falta la copia"
                $desincronizados += "$plugin/$($item.Archivo) (falta)"
            }
            continue
        }

        New-Item -ItemType Directory -Force $carpeta | Out-Null
        Copy-Item $maestra $destino -Force
        $copiados++
        Bien "$plugin — copiado"
    }
    Write-Host ""
}

# ---------------------------------------------------------------------------
Write-Host ("-" * 64) -ForegroundColor DarkGray
if ($Verificar) {
    if ($desincronizados.Count -eq 0) {
        Write-Host " Todas las copias coinciden con shared/." -ForegroundColor Green
        Write-Host ""
        exit 0
    }
    Write-Host " HAY COPIAS DESINCRONIZADAS" -ForegroundColor Red
    foreach ($d in $desincronizados) { Write-Host "   - $d" -ForegroundColor Red }
    Write-Host ""
    Write-Host " Se arregla con:  .\tools\sincronizar-compartido.ps1" -ForegroundColor Yellow
    Write-Host " Ojo: eso pisa la copia del plugin. Si el cambio bueno estaba" -ForegroundColor Yellow
    Write-Host " ahí y no en shared/, pasalo primero a shared/." -ForegroundColor Yellow
    Write-Host ""
    exit 1
}

if ($copiados -eq 0) {
    Write-Host " Ya estaba todo al día." -ForegroundColor Green
} else {
    Write-Host " $copiados copias actualizadas." -ForegroundColor Green
}
Write-Host ""
exit 0
