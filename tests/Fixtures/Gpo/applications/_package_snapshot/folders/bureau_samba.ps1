$Q = New-Object -ComObject shell.application

# Unpin bureau local
$Path = $Q.Namespace("$env:USERPROFILE\Desktop")
$Q.Namespace('shell:::{679f85cb-0220-4080-b29b-5540cc05aab6}').Items() | Foreach-Object {
    If ($_.Path -eq $Path.Self.Path) {
        Write-Host "Unpin " $Path.Self.Path
        $Path.Self.InvokeVerb('pintohome')
    }
}

# pin bureau
$Path = $Q.Namespace('shell:Desktop')
$Pin = $false
$Q.Namespace('shell:::{679f85cb-0220-4080-b29b-5540cc05aab6}').Items() | Foreach-Object {
    If ($_.Path -eq $Path.Self.Path) {
        $Pin = $true
        Break
    }
}
If (-not $Pin) {
    Write-Host "Pin " $Path.Self.Path
    $Path.Self.InvokeVerb('pintohome')
}

# pin Telechargements
$Path = $Q.Namespace('shell:Downloads')
$Pin = $false
$Q.Namespace('shell:::{679f85cb-0220-4080-b29b-5540cc05aab6}').Items() | Foreach-Object {
    If ($_.Path -eq $Path.Self.Path) {
        $Pin = $true
        Break
    }
}
If (-not $Pin) {
    Write-Host "Pin " $Path.Self.Path
    $Path.Self.InvokeVerb('pintohome')
}
