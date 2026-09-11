$ErrorActionPreference = "Stop"

$dir = Split-Path -Parent $MyInvocation.MyCommand.Path
if (-not $dir) { $dir = (Get-Location).Path }

$devHtml = [System.IO.File]::ReadAllText("$dir\index.dev.html", [System.Text.Encoding]::UTF8)
$css = [System.IO.File]::ReadAllText("$dir\css\style.css", [System.Text.Encoding]::UTF8)
$js = [System.IO.File]::ReadAllText("$dir\js\app.js", [System.Text.Encoding]::UTF8)

# Replace CSS links with inline <style>
$cssPattern = '(?s)\s*<link\s+rel="stylesheet"\s+href="css/style\.css[^"]*"\s*/>\s*<link\s+rel="stylesheet"\s+href="style\.css[^"]*"\s*/>'
$styleBlock = "`n  <style>`n$css`n  </style>"
$html = [System.Text.RegularExpressions.Regex]::Replace($devHtml, $cssPattern, [System.Text.RegularExpressions.MatchEvaluator]{ param($m) return $styleBlock }, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)

# Replace JS script tag with inline <script>
$jsPattern = '(?s)\s*<script\s+src="js/app\.js[^"]*"[^>]*></script>'
$scriptBlock = "`n  <script>`n$js`n  </script>"
$html = [System.Text.RegularExpressions.Regex]::Replace($html, $jsPattern, [System.Text.RegularExpressions.MatchEvaluator]{ param($m) return $scriptBlock }, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)

[System.IO.File]::WriteAllText("$dir\index.html", $html, [System.Text.Encoding]::UTF8)
Write-Output "Successfully compiled index.html! Total length: $($html.Length) chars"
