$securePassword = Read-Host "Choose an admin password (12+ characters)" -AsSecureString
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)

try {
    $env:ADMIN_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
    & 'C:\xampp812\php\php.exe' `
      'C:\xampp812\htdocs\school_bookol\bin\create_admin.php' `
      'your-school-id' 'your-email@school.edu' 'Your' 'Name'
}
finally {
    Remove-Item Env:ADMIN_PASSWORD -ErrorAction SilentlyContinue
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
    Remove-Variable securePassword
}