param(
    [string]$BaseUrl = 'http://localhost:8080',
    [int]$WarmRuns = 2
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ($WarmRuns -lt 1) {
    throw 'WarmRuns must be at least 1.'
}

$repoRoot = Split-Path -Parent $PSScriptRoot

$cases = @(
    @{
        Label = 'broad'
        Url = "$BaseUrl/?star_range=all&max_repositories=5000&sort=stars&page=2"
    },
    @{
        Label = 'narrow'
        Url = "$BaseUrl/?star_range=100_999&max_repositories=500&sort=stars&page=2"
    }
)

$headerNames = @(
    'X-Stargazer-Controller-Ms',
    'X-Stargazer-Resolve-Scope-Ids-Ms',
    'X-Stargazer-Total-Ms',
    'X-Stargazer-Scoped-Ids-Cache-Hit',
    'X-Stargazer-Scoped-Ids-Total-Ms'
)

function Invoke-TimedRequest {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Url
    )

    $headerFile = [System.IO.Path]::GetTempFileName()
    try {
        $curlArgs = @(
            '-s',
            '-D', $headerFile,
            '-o', 'NUL',
            '-w', 'TOTAL=%{time_total}',
            $Url
        )

        $curlOutput = & curl.exe @curlArgs
        $headers = Get-Content $headerFile

        $statusLine = $headers | Where-Object { $_ -match '^HTTP/' } | Select-Object -Last 1
        $statusCode = if ($statusLine -match '^HTTP/\S+\s+(\d{3})') { $matches[1] } else { 'unknown' }
        $totalSeconds = if ($curlOutput -match 'TOTAL=([0-9.]+)') { $matches[1] } else { 'unknown' }

        $result = [ordered]@{
            Status = $statusCode
            TotalSeconds = $totalSeconds
        }

        foreach ($headerName in $headerNames) {
            $headerLine = $headers | Where-Object { $_ -like "${headerName}:*" } | Select-Object -Last 1
            $result[$headerName] = if ($null -ne $headerLine) {
                ($headerLine -split ':', 2)[1].Trim()
            } else {
                ''
            }
        }

        return $result
    } finally {
        Remove-Item $headerFile -ErrorAction SilentlyContinue
    }
}

Write-Host 'Clearing prod cache for cold-run baseline...'
& docker-compose exec -T web php bin/console cache:clear --env=prod --no-warmup | Out-Null

foreach ($case in $cases) {
    Write-Host ''
    Write-Host ('=== {0} ===' -f $case.Label.ToUpperInvariant())
    Write-Host $case.Url

    $coldResult = Invoke-TimedRequest -Url $case.Url
    Write-Host ('cold  status={0} total={1}s controller={2} resolve_scope_ids={3} total_ms={4} cache_hit={5} scoped_ids_total={6}' -f
        $coldResult.Status,
        $coldResult.TotalSeconds,
        $coldResult['X-Stargazer-Controller-Ms'],
        $coldResult['X-Stargazer-Resolve-Scope-Ids-Ms'],
        $coldResult['X-Stargazer-Total-Ms'],
        $coldResult['X-Stargazer-Scoped-Ids-Cache-Hit'],
        $coldResult['X-Stargazer-Scoped-Ids-Total-Ms']
    )

    for ($run = 1; $run -le $WarmRuns; $run++) {
        $warmResult = Invoke-TimedRequest -Url $case.Url
        Write-Host ('warm{0} status={1} total={2}s controller={3} resolve_scope_ids={4} total_ms={5} cache_hit={6} scoped_ids_total={7}' -f
            $run,
            $warmResult.Status,
            $warmResult.TotalSeconds,
            $warmResult['X-Stargazer-Controller-Ms'],
            $warmResult['X-Stargazer-Resolve-Scope-Ids-Ms'],
            $warmResult['X-Stargazer-Total-Ms'],
            $warmResult['X-Stargazer-Scoped-Ids-Cache-Hit'],
            $warmResult['X-Stargazer-Scoped-Ids-Total-Ms']
        )
    }
}
