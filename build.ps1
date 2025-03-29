# Script para crear un archivo ZIP del plugin WordPress
# Elimina build directory si existe y crea uno nuevo
if (Test-Path -Path "build") {
    Remove-Item -Path "build" -Recurse -Force
}
New-Item -ItemType Directory -Path "build" -Force | Out-Null

Write-Host "Empaquetando el plugin WordPress..."

# Definir elementos a excluir
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

# Crear directorio temporal para los archivos
$tempDir = "build\temp"
New-Item -ItemType Directory -Path $tempDir -Force | Out-Null

# Obtener todos los archivos y carpetas excluyendo los no deseados
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

# Copiar archivos al directorio temporal manteniendo la estructura relativa
foreach ($file in $filesToInclude) {
    $relativePath = $file.FullName.Substring($PWD.Path.Length + 1)
    $destination = Join-Path $tempDir $relativePath
    $destinationDir = Split-Path -Parent $destination
    
    if (!(Test-Path $destinationDir)) {
        New-Item -ItemType Directory -Path $destinationDir -Force | Out-Null
    }
    Copy-Item $file.FullName -Destination $destination -Force
}

# Crear el archivo ZIP desde el directorio temporal
$pluginDirName = "turpialapp-for-woo"
$finalTempDir = Join-Path $tempDir $pluginDirName
New-Item -ItemType Directory -Path $finalTempDir -Force | Out-Null

# Mover todo del directorio temporal a un subdirectorio con el nombre del plugin
Get-ChildItem -Path $tempDir -Exclude $pluginDirName | Move-Item -Destination $finalTempDir -Force

# Comprimir el directorio que contiene la carpeta del plugin
Compress-Archive -Path "$finalTempDir" -DestinationPath "build\turpialapp-for-woo.zip" -Force

# Limpiar directorio temporal
Remove-Item -Path $tempDir -Recurse -Force

Write-Host "Plugin empaquetado exitosamente en: build\turpialapp-for-woo.zip"
Write-Host "Tamaño del archivo: $((Get-Item 'build\turpialapp-for-woo.zip').Length / 1KB) KB"