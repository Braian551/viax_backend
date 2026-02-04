@echo off
echo Installing Python dependencies for Viax Biometrics...
echo.

:: Check if python is installed
python --version >nul 2>&1
if %errorlevel% neq 0 (
    echo Error: Python is not installed or not in PATH.
    echo Please install Python (3.7+) from python.org
    pause
    exit /b
)

:: Install cmake ensuring it's available (needed for dlib)
echo Installing CMake (prerequisite for dlib)...
pip install cmake

:: Install requirements
echo Installing libraries from requirements.txt...
pip install -r requirements.txt

if %errorlevel% neq 0 (
    echo.
    echo Error installing dependencies. 
    echo Note: 'dlib' often requires Visual Studio C++ build tools to be installed on Windows.
    echo If you see errors related to CMake or C++ compilation, please install "Desktop development with C++" from Visual Studio Installer.
) else (
    echo.
    echo Success! All dependencies installed.
)

pause
