import pathlib
import shutil
import subprocess
import tarfile
import tempfile
from typing import List

import modal

volume = modal.Volume.from_name("openclaw-apk")

def run(cmd: List[str], cwd: pathlib.Path) -> None:
    print("run:", cmd, "cwd=", cwd)
    subprocess.run(cmd, cwd=str(cwd), check=True)

android_image = (
    modal.Image.debian_slim()
    .apt_install("openjdk-17-jdk", "wget", "unzip", "git", "curl")
    .run_commands(
        "mkdir -p /sdk/cmdline-tools",
        "wget https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip -O /sdk/tools.zip",
        "unzip /sdk/tools.zip -d /sdk/cmdline-tools",
        "mv /sdk/cmdline-tools/cmdline-tools /sdk/cmdline-tools/latest",
        "rm /sdk/tools.zip",
    )
    .env({
        "JAVA_HOME": "/usr/lib/jvm/java-17-openjdk-amd64",
        "ANDROID_HOME": "/sdk",
        "ANDROID_SDK_ROOT": "/sdk",
        "PATH": ":/usr/lib/jvm/java-17-openjdk-amd64/bin:/sdk/platform-tools:/sdk/cmdline-tools/latest/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
    })
    .run_commands(
        "yes | sdkmanager --licenses",
        "sdkmanager 'platform-tools' 'platforms;android-34' 'build-tools;34.0.0'",
    )
    .run_commands(
        "curl -fsSL https://deb.nodesource.com/setup_22.x | bash -",
        "apt-get install -y nodejs",
    )
)

app = modal.App()

def ensure_dir(path: pathlib.Path) -> None:
    path.mkdir(parents=True, exist_ok=True)


def extract_archive(archive_path: pathlib.Path, workdir: pathlib.Path) -> pathlib.Path:
    with tarfile.open(archive_path, mode="r:gz") as tar:
        tar.extractall(path=workdir)
    # Check if we have an openclaw dir, otherwise use workdir as root
    repo_root = workdir / "openclaw"
    if not repo_root.exists():
        repo_root = workdir
    return repo_root


@app.function(image=android_image, volumes={"/vol/apk": volume}, cpu=4.0, memory=16384)
def build_apk_remote() -> None:
    archive_path = pathlib.Path("/vol/apk/project-v4.tar.gz")
    if not archive_path.exists():
        raise FileNotFoundError(f"Project archive missing at {archive_path}")

    workdir = pathlib.Path(tempfile.mkdtemp(prefix="openclaw-modal-"))
    try:
        repo_root = extract_archive(archive_path, workdir)
        ui_dir = repo_root / "ui"
        if not ui_dir.exists():
            raise RuntimeError("ui directory missing in repo")

        run(["npm", "install"], ui_dir)
        run(["npm", "run", "build"], ui_dir)
        run(["npx", "cap", "copy", "android"], ui_dir)
        run(["npx", "@capacitor/assets", "generate", "--android"], ui_dir)
        run(["npx", "cap", "sync", "android"], ui_dir)

        duplicate = ui_dir / "android/app/src/main/res/values/ic_launcher_background.xml"
        if duplicate.exists():
            duplicate.unlink()

        local_props = ui_dir / "android/local.properties"
        local_props.write_text("sdk.dir=/sdk\n")

        run(["./gradlew", ":app:assembleDebug", "--no-daemon"], ui_dir / "android")

        apk_src = ui_dir / "android/app/build/outputs/apk/debug/app-debug.apk"
        dest = pathlib.Path("/vol/apk/app-debug.apk")
        ensure_dir(dest.parent)
        shutil.copy(apk_src, dest)
    finally:
        shutil.rmtree(workdir)
