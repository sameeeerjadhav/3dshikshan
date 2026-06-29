import re
import os

def process_guide(text):
    lines = text.split('\n')
    formatted = []
    in_list = False
    
    for line in lines:
        line = line.strip()
        if not line:
            if in_list:
                formatted.append('</ul>')
                in_list = False
            continue
            
        # Detect lists (bullet points or checkmarks or question marks at start)
        if re.match(r'^[\?\✅\❌\•\-]\s*', line) or line.startswith('Step '):
            if not in_list:
                formatted.append('<ul>')
                in_list = True
            clean_line = re.sub(r'^[\?\✅\❌\•\-]\s*', '', line)
            if line.startswith('Step '):
                formatted.append(f'<li><strong>{clean_line}</strong></li>')
            else:
                formatted.append(f'<li>{clean_line}</li>')
        # Detect headings
        elif line.endswith('?') or (len(line.split()) <= 8 and not line.endswith('.')):
            if in_list:
                formatted.append('</ul>')
                in_list = False
            formatted.append(f'<h2>{line}</h2>')
        else:
            if in_list:
                formatted.append('</ul>')
                in_list = False
            # Check for inline links
            line = re.sub(r'(https?://[^\s]+)', r'<a href="\1" target="_blank">\1</a>', line)
            formatted.append(f'<p>{line}</p>')
            
    if in_list:
        formatted.append('</ul>')
    return '\n'.join(formatted)

def process_file(filepath, title):
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    match = re.search(r'<p>(.*?)</p>', content, re.DOTALL)
    if not match: return
    text = match.group(1)
    
    html = process_guide(text)
    
    out = '<?php\nrequire_once __DIR__ . "/config.php";\nrequire_once __DIR__ . "/includes/legal.php";\nlegal_render_head("' + title + '");\n?>\n'
    out += html
    out += '\n<?php\nlegal_render_foot();\n?>\n'
    
    with open(filepath, 'w', encoding='utf-8') as f:
        f.write(out)
    print(f"Processed {filepath}")

process_file('guide_hardware.php', 'Guide & Inventory')
process_file('guide_process.php', 'Guide & Inventory')
process_file('inv_slicing_tools.php', 'Guide & Inventory')
process_file('inv_design_tools.php', 'Guide & Inventory')
