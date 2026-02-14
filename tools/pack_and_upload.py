#!/usr/bin/env python3
import os, sys, time, subprocess
from pathlib import Path

WORKDIR = Path('/home/ubuntu/.openclaw/workspace/temp_openclaw_clone')
FILES_LIST = Path('/tmp/openclaw_all_files.txt')
FLAGGED = Path('/tmp/openclaw_flagged_secrets.txt')
NLM = '/home/ubuntu/.local/bin/nlm'

if not WORKDIR.exists():
    print('Workdir missing:', WORKDIR)
    sys.exit(1)
if not FILES_LIST.exists():
    print('Files list missing:', FILES_LIST)
    sys.exit(1)

# read files
with FILES_LIST.open() as f:
    files = [line.strip() for line in f if line.strip()]

flagged_paths = set()
if FLAGGED.exists():
    with FLAGGED.open() as f:
        for line in f:
            p = line.split(':',1)[0].strip()
            if p:
                flagged_paths.add(p)

# categorize
code_exts = {'.js','.ts','.py','.go','.rs','.java','.sh','.json','.yaml','.yml','.c','.cpp','.h','.hpp','.cs','.scala','.kt','.kts','.dart','.tsx','.jsx','.md','.toml','.ini','.lock','.ps1'}
docs_prefix = './docs/'
code_files = []
docs_files = []
for p in files:
    if p.startswith(docs_prefix):
        docs_files.append(p)
    else:
        ext = Path(p).suffix.lower()
        if ext in code_exts:
            code_files.append(p)

print('Code files:', len(code_files), 'Docs files:', len(docs_files))

# helper to write XML pack
def write_pack(path, file_list):
    with open(path, 'w', encoding='utf8', errors='replace') as out:
        out.write('<?xml version="1.0" encoding="utf-8"?>\n')
        out.write('<repository name="openclaw" generated="{}">\n'.format(time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())))
        for p in file_list:
            full = WORKDIR / p
            if not full.exists():
                continue
            # skip flagged secrets files to be safe
            if p in flagged_paths:
                out.write(f'  <file path="{p}" flagged="true">REDACTED</file>\n')
                continue
            try:
                txt = full.read_text(errors='replace')
            except Exception as e:
                print('Read error', p, e)
                continue
            # escape CDATA end
            txt = txt.replace(']]>', ']]>]]<![CDATA[>')
            out.write(f'  <file path="{p}"><![CDATA[{txt}]]></file>\n')
        out.write('</repository>\n')
    print('Wrote pack', path, 'size', path.stat().st_size)

code_pack = WORKDIR / 'repomix-code.xml'
docs_pack = WORKDIR / 'repomix-docs.xml'
write_pack(code_pack, code_files)
write_pack(docs_pack, docs_files)

# create notebook
title = f'OpenClaw — repomix snapshot — {time.strftime("%Y-%m-%d %H:%M:%S UTC", time.gmtime())}'
print('Creating notebook with title:', title)
proc = subprocess.run([NLM, 'notebook', 'create', title], capture_output=True, text=True)
if proc.returncode != 0:
    print('Failed to create notebook:', proc.stderr)
    sys.exit(1)
nb_id = None
for line in proc.stdout.splitlines():
    if line.strip().startswith('ID:'):
        nb_id = line.split('ID:',1)[1].strip()
        break
if not nb_id:
    # try JSON parse
    try:
        import json
        j = json.loads(proc.stdout)
        nb_id = j[0]['id']
    except Exception:
        print('Could not find notebook id in output:', proc.stdout)
        sys.exit(1)
print('Notebook ID:', nb_id)

# upload packs (use nlm source add with --text)
for pack in [code_pack, docs_pack]:
    print('Uploading', pack.name)
    txt = pack.read_text(errors='replace')
    # chunk if too big? nlm seems to accept large text; do in one call
    cmd = [NLM, 'source', 'add', nb_id, '--text', txt, '--title', pack.name]
    # run as ubuntu user
    proc = subprocess.run(['sudo','-u','ubuntu'] + cmd, capture_output=True, text=True)
    if proc.returncode != 0:
        print('Upload failed for', pack.name, proc.stderr[:400])
    else:
        print('Uploaded', pack.name)

print('Done. Notebook ID:', nb_id)
print('You can query: {} notebook describe {}'.format(NLM, nb_id))
