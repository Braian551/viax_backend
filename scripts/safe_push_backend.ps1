param(
    [Parameter(Mandatory = $true)]
    [string]$Message,

    [Parameter(Mandatory = $true)]
    [string[]]$Files,

    [switch]$Push
)

$ErrorActionPreference = 'Stop'

Write-Host "[safe-push] Validando repo backend..."

$repoRoot = git rev-parse --show-toplevel
if (-not $repoRoot) {
    throw "No se detecto repositorio git."
}

Set-Location $repoRoot

$changes = git status --short
if (-not $changes) {
    Write-Host "[safe-push] No hay cambios locales."
    exit 0
}

Write-Host "[safe-push] Cambios actuales detectados:"
$changes | ForEach-Object { Write-Host "  $_" }

# Staging explicito solo de los archivos indicados
foreach ($file in $Files) {
    if (-not (Test-Path $file)) {
        throw "Archivo no encontrado: $file"
    }
    git add -- "$file"
}

$staged = git diff --cached --name-only
if (-not $staged) {
    throw "No hay archivos staged despues de git add."
}

Write-Host "[safe-push] Archivos staged:"
$staged | ForEach-Object { Write-Host "  $_" }

# Si hay otros cambios staged no incluidos, abortar.
$expected = @($Files | ForEach-Object { $_.Replace('\\', '/') })
$unexpected = @()
foreach ($s in $staged) {
    $normalized = $s.Replace('\\', '/')
    if ($expected -notcontains $normalized) {
        $unexpected += $normalized
    }
}

if ($unexpected.Count -gt 0) {
    Write-Host "[safe-push] ERROR: hay archivos staged inesperados:" -ForegroundColor Red
    $unexpected | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }
    throw "Aborta para evitar push de archivos antiguos/no deseados."
}

git commit -m $Message

if ($Push) {
    git push origin HEAD:main
    Write-Host "[safe-push] Push realizado a origin/main"
} else {
    Write-Host "[safe-push] Commit creado. Usa -Push para empujar automaticamente."
}
