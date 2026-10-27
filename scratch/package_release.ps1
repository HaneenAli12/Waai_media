$zipPath = "deployment/waai_media_api_release_700aefc.zip"
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

# Gather tracked files from git at current commit
$trackedFiles = git ls-files

$filesToInclude = @()

foreach ($f in $trackedFiles) {
    # Skip excluded directories
    if ($f.StartsWith("tests/") -or
        $f.StartsWith("scratch/") -or
        $f.StartsWith("event-module-staging/") -or
        $f.StartsWith("deployment/") -or
        $f.StartsWith("storage/")) {
        continue
    }

    # Skip excluded files
    if ($f -eq "phpunit.xml" -or
        $f -eq "test-r2.php" -or
        $f -eq "test_login.php" -or
        $f -eq "event-calendar-deploy.zip" -or
        $f -eq "database/seeders/SuperAdminSeeder.php") {
        continue
    }

    $filesToInclude += $f
}

Write-Host "Files to pack:" $filesToInclude.Count

# Create temporary staging directory for clean zipping
$stagingDir = "scratch/release_staging"
if (Test-Path $stagingDir) { Remove-Item $stagingDir -Recurse -Force }
New-Item -ItemType Directory -Path $stagingDir | Out-Null

foreach ($f in $filesToInclude) {
    $targetPath = Join-Path $stagingDir $f
    $targetParent = Split-Path $targetPath -Parent
    if (-not (Test-Path $targetParent)) {
        New-Item -ItemType Directory -Path $targetParent | Out-Null
    }
    Copy-Item $f -Destination $targetPath
}

# Compress staging directory
Compress-Archive -Path "$stagingDir/*" -DestinationPath $zipPath -CompressionLevel Optimal

# Cleanup staging directory
Remove-Item $stagingDir -Recurse -Force

Write-Host "Zip created successfully at:" $zipPath
Write-Host "Size in KB:" ((Get-Item $zipPath).Length / 1KB)
