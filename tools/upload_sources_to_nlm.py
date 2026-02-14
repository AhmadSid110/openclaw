#!/usr/bin/env python3
import os, sys, subprocess, time, shlex
from pathlib import Path

REPO_DIR = Path('/home/ubuntu/.openclaw/workspace/temp_openclaw_clone')
NLMBIN = '/home/ubuntu/.local/bin/nlm'

# extensions considered source code for debugging
EXTS = {'.js','.ts','.py','.go','.rs','.java','.sh','.json','.yaml','.yml','.c','.cpp','.h','.hpp','.cs','.scala','.kt','.kts','.dart','.tsx','.jsx','.md','.toml','.ini','.lock','.ps1'}

# load candidate files list
all_files_path = Path('/tmp/openclaw_all_files.txt')
if not all_files_path.exists():
    print('Files list not found at', all_files_path)
    sys.exit(1)

with all_files_path.open() as f:
    files = [line.strip() for line in f if line.strip()]

# filter
candidates = []
for p in files:
    pp = Path(p)
    if pp.suffix.lower() in EXTS:
        candidates.append(pp)

print(f"Found {len(candidates)} candidate source files")
if len(candidates)==0:
    sys.exit(0)

# further filter: skip files flagged as secrets
flagged = set()
flagged_path = Path('/tmp/openclaw_flagged_secrets.txt')
if flagged_path.exists():
    with flagged_path.open() as f:
        for line in f:
            line=line.strip()
            if line:
                parts=line.split(':',1)
                flagged.add(parts[0])

selected = [p for p in candidates if p not in flagged]
skipped_flagged = [p for p in candidates if p in flagged]
print(f"Selected {len(selected)} files after removing {len(skipped_flagged)} flagged files")

# create notebook
title = f"OpenClaw source snapshot (debug) - {time.strftime('%Y-%m-%d %H:%M:%S UTC') }"
print('Creating notebook:', title)
proc = subprocess.run([NLMBIN, 'notebook', 'create', title], capture_output=True, text=True)
if proc.returncode!=0:
    print('Failed to create notebook:', proc.stderr)
    sys.exit(1)
# parse output to find ID
out = proc.stdout
nb_id = None
for line in out.splitlines():
    line=line.strip()
    if line.startswith('ID:'):
        nb_id=line.split('ID:')[1].strip()
        break
# Sometimes output is JSON-like; try to parse
if not nb_id:
    # try reading stderr
    for line in proc.stdout.splitlines():
        if 'ID:' in line:
            nb_id=line.split('ID:')[-1].strip()
            break
if not nb_id:
    print('Could not determine notebook ID. Output:\n', proc.stdout, proc.stderr)
    sys.exit(1)
print('Notebook ID:', nb_id)

# upload files
errors = []
for p in selected:
    full = REPO_DIR / p
    if not full.exists():
        print('Missing', full)
        continue
    size = full.stat().st_size
    if size>500000: # 500KB chunk rule
        print('Skipping large file (>',size,')',p)
        continue
    try:
        content = full.read_text(errors='replace')
    except Exception as e:
        print('Read error', full, e)
        errors.append((p,str(e)))
        continue
    title = str(p)
    print('Uploading', title, f'({size} bytes)')
    # call nlm
    cmd = [NLMBIN, 'source', 'add', nb_id, '--text', content, '--title', title]
    # run
    proc = subprocess.run(cmd, capture_output=True, text=True)
    if proc.returncode!=0:
        print('Upload failed for',title,proc.stderr[:200])
        errors.append((p,proc.stderr.strip()))
    else:
        print('Uploaded',title)

print('\nDone. Uploaded', 'files:', len(selected)-len(errors))
if errors:
    print('Errors:', errors[:10])
print('Notebook ID:', nb_id)
print('You can query with: /home/ubuntu/.local/bin/nlm notebook describe', nb_id)
