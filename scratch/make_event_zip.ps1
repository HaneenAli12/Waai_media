$stage = "event-update-clean"
if (Test-Path $stage) {
    Remove-Item -Recurse -Force $stage
}
New-Item -ItemType Directory -Path $stage -Force | Out-Null

$files = @(
    "app/Models/Event.php",
    "app/Http/Controllers/Api/EventController.php",
    "app/Http/Controllers/Api/Admin/EventController.php",
    "app/Http/Requests/Admin/Events/StoreEventRequest.php",
    "app/Http/Requests/Admin/Events/UpdateEventRequest.php",
    "app/Http/Requests/Admin/Events/PublishEventRequest.php",
    "app/Http/Requests/Admin/Events/IndexEventRequest.php",
    "app/Http/Requests/Events/IndexPublicEventRequest.php",
    "app/Http/Resources/EventAdminResource.php",
    "app/Http/Resources/EventPublicResource.php",
    "database/migrations/2026_10_24_000001_create_events_table.php",
    "database/migrations/2026_10_24_000002_add_bilingual_author_names_to_events_table.php",
    "database/factories/EventFactory.php"
)

foreach ($item in $files) {
    $dest = Join-Path $stage $item
    $destDir = Split-Path $dest -Parent
    if (-not (Test-Path $destDir)) {
        New-Item -ItemType Directory -Path $destDir -Force | Out-Null
    }
    Copy-Item $item -Destination $dest
}

$zip = "event-calendar-update.zip"
if (Test-Path $zip) {
    Remove-Item -Force $zip
}

Compress-Archive -Path "$stage/*" -DestinationPath $zip -Force
Remove-Item -Recurse -Force $stage
Get-Item $zip | Select-Object FullName, Length, LastWriteTime
