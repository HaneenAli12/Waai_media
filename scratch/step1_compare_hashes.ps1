Add-Type -AssemblyName System.IO.Compression.FileSystem

$zipPath = "C:\Projects\Waai_media\event-calendar-deploy.zip"
$zip = [System.IO.Compression.ZipFile]::OpenRead($zipPath)

$expectedFiles = @(
    "app/Http/Controllers/Api/EventController.php",
    "app/Http/Controllers/Api/Admin/EventController.php",
    "app/Http/Requests/Admin/Events/IndexEventRequest.php",
    "app/Http/Requests/Admin/Events/StoreEventRequest.php",
    "app/Http/Requests/Admin/Events/UpdateEventRequest.php",
    "app/Http/Requests/Admin/Events/PublishEventRequest.php",
    "app/Http/Requests/Events/IndexPublicEventRequest.php",
    "app/Http/Resources/EventAdminResource.php",
    "app/Http/Resources/EventPublicResource.php",
    "app/Models/Event.php",
    "app/Services/Events/EventCoverImageService.php",
    "app/Services/Events/EventDateTimeService.php",
    "app/Services/Events/EventSlugService.php",
    "database/factories/EventFactory.php",
    "database/migrations/2026_10_24_000001_create_events_table.php"
)

$forbiddenRegex = "\b(author_name|author_name_en|author_name_ar|journalist_name|EventCategory|event_category_id|event_categories)\b"

Write-Output "--- FILE COMPARISON & HASH CHECK ---"

$tempExtract = "C:\Projects\Waai_media\scratch\temp_zip_check"
if (Test-Path $tempExtract) {
    Remove-Item -Recurse -Force $tempExtract
}
New-Item -ItemType Directory -Path $tempExtract -Force | Out-Null
[System.IO.Compression.ZipFile]::ExtractToDirectory($zipPath, $tempExtract)

foreach ($rel in $expectedFiles) {
    $normRel = $rel.Replace('/', '\')
    $extractedFile = Join-Path $tempExtract $normRel
    $workingFile = Join-Path "C:\Projects\Waai_media" $normRel

    if (-not (Test-Path $extractedFile)) {
        Write-Output ("MISSING_IN_ZIP: {0}" -f $rel)
        continue
    }
    if (-not (Test-Path $workingFile)) {
        Write-Output ("MISSING_IN_TREE: {0}" -f $rel)
        continue
    }

    $zipHash = (Get-FileHash -Path $extractedFile -Algorithm SHA256).Hash
    $workHash = (Get-FileHash -Path $workingFile -Algorithm SHA256).Hash
    $match = ($zipHash -eq $workHash)

    Write-Output ("FILE: {0} | MATCH: {1} | ZIP_HASH: {2} | WORK_HASH: {3}" -f $rel, $match, $zipHash, $workHash)

    # Check for forbidden keywords in extracted file
    $content = Get-Content -Path $extractedFile -Raw
    $matches = [regex]::Matches($content, $forbiddenRegex)
    if ($matches.Count -gt 0) {
        foreach ($m in $matches) {
            Write-Output ("  FORBIDDEN_KEYWORD_FOUND: {0} at index {1} in {2}" -f $m.Value, $m.Index, $rel)
        }
    }
}

Remove-Item -Recurse -Force $tempExtract
$zip.Dispose()
