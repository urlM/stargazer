param(
    [int]$MinCpus = 4,
    [int]$MinMemoryGiB = 6
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ($MinCpus -lt 1) {
    throw 'MinCpus must be at least 1.'
}

if ($MinMemoryGiB -lt 1) {
    throw 'MinMemoryGiB must be at least 1.'
}

try {
    $temporaryDockerConfig = Join-Path ([System.IO.Path]::GetTempPath()) ('stargazer-docker-config-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $temporaryDockerConfig | Out-Null
    try {
        $dockerInfo = & docker --config $temporaryDockerConfig info --format '{{.NCPU}} {{.MemTotal}}' 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw ($dockerInfo | Out-String).Trim()
        }
    } finally {
        Remove-Item -LiteralPath $temporaryDockerConfig -Recurse -Force -ErrorAction SilentlyContinue
    }
} catch {
    Write-Error @"
Docker resource preflight could not read Docker engine capacity.

Make sure Docker Desktop is running and this shell has permission to access Docker.
Original error: $($_.Exception.Message)
"@
    exit 1
}

$parts = ($dockerInfo | Out-String).Trim() -split '\s+'
if ($parts.Count -lt 2) {
    Write-Error "Unexpected docker info output: $dockerInfo"
    exit 1
}

$availableCpus = [int]$parts[0]
$availableMemoryBytes = [int64]$parts[1]
$availableMemoryGiB = [math]::Round($availableMemoryBytes / 1GB, 2)

Write-Host ('Docker engine capacity: CPUs={0}, Memory={1} GiB' -f $availableCpus, $availableMemoryGiB)
Write-Host ('Repo minimum baseline: CPUs={0}, Memory={1} GiB' -f $MinCpus, $MinMemoryGiB)

$errors = @()
if ($availableCpus -lt $MinCpus) {
    $errors += "CPU allocation is below the repo minimum ($availableCpus < $MinCpus)."
}

if ($availableMemoryBytes -lt ($MinMemoryGiB * 1GB)) {
    $errors += "Memory allocation is below the repo minimum ($availableMemoryGiB GiB < $MinMemoryGiB GiB)."
}

if ($errors.Count -gt 0) {
    Write-Host ''
    Write-Host 'Docker resource preflight failed:' -ForegroundColor Red
    foreach ($errorMessage in $errors) {
        Write-Host ('- {0}' -f $errorMessage) -ForegroundColor Red
    }

    Write-Host ''
    Write-Host 'Recommended fix in Docker Desktop:' -ForegroundColor Yellow
    Write-Host ('- Set CPUs to at least {0}' -f $MinCpus) -ForegroundColor Yellow
    Write-Host ('- Set Memory to at least {0} GiB' -f $MinMemoryGiB) -ForegroundColor Yellow
    Write-Host '- Restart the Docker stack after changing resource settings' -ForegroundColor Yellow
    exit 1
}

Write-Host 'Docker resource preflight passed.' -ForegroundColor Green
