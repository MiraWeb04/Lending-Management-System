import os
import re

root = r'c:\xampp\htdocs\lending_system\lending_system'
modal_pattern = re.compile(r'<div[^>]+class\s*=\s*(["\"])(?:(?!\1).)*\bmodal\b(?:(?!\1).)*\1[^>]*>', re.I | re.S)
form_pattern = re.compile(r'<form[^>]*>', re.I)
control_pattern = re.compile(r'<(input|select|textarea)([^>]*)>', re.I)
name_pattern = re.compile(r'name\s*=\s*(?:"([^"]*)"|\'([^\']*)\')', re.I)

issues = []
for dirpath, dirnames, filenames in os.walk(root):
    for fname in filenames:
        if not fname.lower().endswith('.php'):
            continue
        path = os.path.join(dirpath, fname)
        text = open(path, 'r', encoding='utf-8', errors='ignore').read()
        for modal_match in modal_pattern.finditer(text):
            start = modal_match.start()
            depth = 0
            pos = start
            while pos < len(text):
                if text[pos:pos+4].lower() == '<div':
                    depth += 1
                    pos += 4
                elif text[pos:pos+6].lower() == '</div>':
                    depth -= 1
                    pos += 6
                    if depth == 0:
                        modal_text = text[start:pos]
                        break
                else:
                    pos += 1
            else:
                modal_text = text[start:]
            for form_match in form_pattern.finditer(modal_text):
                form_text = modal_text[form_match.end():]
                form_end = form_text.lower().find('</form>')
                if form_end != -1:
                    form_text = form_text[:form_end]
                for control_match in control_pattern.finditer(form_text):
                    tag = control_match.group(1)
                    attrs = control_match.group(2)
                    if 'name=' not in attrs.lower():
                        issues.append((path, modal_match.group(0), form_match.group(0), tag, attrs.strip()))
                    else:
                        nm = name_pattern.search(attrs)
                        if nm and ((nm.group(1) or nm.group(2)) == ''):
                            issues.append((path, modal_match.group(0), form_match.group(0), tag, attrs.strip()))

if issues:
    print(f'Found {len(issues)} modal form control issue(s)')
    for idx, (path, modal, form_tag, tag, attrs) in enumerate(issues, 1):
        print(f'{idx}. File: {path}')
        print(f'   Modal start: {modal}')
        print(f'   Form tag: {form_tag}')
        print(f'   Control: <{tag} {attrs}>')
        print('')
else:
    print('No modal form control issues found.')
