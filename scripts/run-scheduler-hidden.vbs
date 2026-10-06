Option Explicit
Dim shell, files, projectDirectory, installationDirectory, phpBinary, command, exitCode
Set shell = CreateObject("WScript.Shell")
Set files = CreateObject("Scripting.FileSystemObject")
projectDirectory = files.GetParentFolderName(files.GetParentFolderName(WScript.ScriptFullName))
installationDirectory = files.GetParentFolderName(projectDirectory)
phpBinary = files.BuildPath(installationDirectory, "runtime\php\php.exe")
If Not files.FileExists(phpBinary) Then WScript.Quit 1
shell.CurrentDirectory = projectDirectory
command = """" & phpBinary & """ """ & files.BuildPath(projectDirectory, "artisan") & """ schedule:run --no-interaction"
exitCode = shell.Run(command, 0, True)
WScript.Quit exitCode
