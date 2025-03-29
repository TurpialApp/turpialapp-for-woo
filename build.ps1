# Script to create a WordPress plugin ZIP file
# Remove build directory if exists and create a new one
if (Test-Path -Path "build") {
    Remove-Item -Path "build" -Recurse -Force
}
New-Item -ItemType Directory -Path "build" -Force | Out-Null

Write-Host "Packaging WordPress plugin..."

# Define items to exclude
$excludedItems = @(
    ".git", 
    "node_modules", 
    "vendor", 
    ".vscode", 
    "build", 
    "phpcs.xml.dist", 
    "composer.json", 
    "composer.lock", 
    "yarn.lock",
    ".phpcs.cache",
    ".gitignore",
    "*.zip",
    "package.json",
    "package-lock.json"
)

# Create temporary directory for files
$tempDir = "build\temp"
New-Item -ItemType Directory -Path $tempDir -Force | Out-Null

# Get all files and folders excluding unwanted ones
$filesToInclude = Get-ChildItem -Path "." -Recurse | Where-Object { 
    $item = $_
    $exclude = $false
    foreach ($excluded in $excludedItems) {
        if ($item.FullName -like "*\$excluded*" -or $item.Name -like $excluded) {
            $exclude = $true
            break
        }
    }
    -not $exclude
}

# Copy files to temporary directory maintaining relative structure
foreach ($file in $filesToInclude) {
    $relativePath = $file.FullName.Substring($PWD.Path.Length + 1)
    $destination = Join-Path $tempDir $relativePath
    $destinationDir = Split-Path -Parent $destination
    
    if (!(Test-Path $destinationDir)) {
        New-Item -ItemType Directory -Path $destinationDir -Force | Out-Null
    }
    Copy-Item $file.FullName -Destination $destination -Force
}

# Create ZIP file from temporary directory
$pluginDirName = "turpialapp-for-woo"
$finalTempDir = Join-Path $tempDir $pluginDirName
New-Item -ItemType Directory -Path $finalTempDir -Force | Out-Null

# Move everything from temp directory to a subdirectory with plugin name
Get-ChildItem -Path $tempDir -Exclude $pluginDirName | Move-Item -Destination $finalTempDir -Force

# Compress the directory containing the plugin folder
Compress-Archive -Path "$finalTempDir" -DestinationPath "build\turpialapp-for-woo.zip" -Force

# Clean up temporary directory
Remove-Item -Path $tempDir -Recurse -Force

Write-Host "Plugin successfully packaged to: build\turpialapp-for-woo.zip"
Write-Host "File size: $((Get-Item 'build\turpialapp-for-woo.zip').Length / 1KB) KB"