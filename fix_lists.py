import re

def fix_design_tools():
    with open('inv_design_tools.php', 'r', encoding='utf-8') as f:
        content = f.read()

    parts = content.split('legal_render_head("Guide & Inventory");\n?>\n')
    if len(parts) < 2: return
    
    header = parts[0] + 'legal_render_head("Guide & Inventory");\n?>\n'
    footer_split = parts[1].split('\n<?php\nlegal_render_foot();')
    body = footer_split[0]
    footer = '\n<?php\nlegal_render_foot();' + footer_split[1]

    lines = body.split('\n')
    new_lines = []
    
    i = 0
    in_list = False
    
    while i < len(lines):
        line = lines[i].strip()
        if not line:
            i += 1
            continue
            
        if line == '<h2>Design Support Tools & Digital Inventory</h2>':
            if in_list:
                new_lines.append('</ul>')
                in_list = False
            new_lines.append('<h2>Design Support Tools & Digital Inventory</h2>')
            i += 1
            continue
            
        # Detect numbered sections e.g. <h2>1. CAD Design Software</h2>
        if re.match(r'^<h2>\d+\.\s', line):
            if in_list:
                new_lines.append('</ul>')
                in_list = False
            new_lines.append(line)
            i += 1
            continue

        # Pair format: <h2>Name</h2> followed by <h2>https://link...</h2>
        if line.startswith('<h2>') and 'http' not in line:
            name = line.replace('<h2>', '').replace('</h2>', '').strip()
            if i + 1 < len(lines) and lines[i+1].startswith('<h2>http'):
                link = lines[i+1].replace('<h2>', '').replace('</h2>', '').strip()
                if not in_list:
                    new_lines.append('<ul>')
                    in_list = True
                new_lines.append(f'<li><a href="{link}" target="_blank">{name}</a></li>')
                i += 2
                continue
                
        # Inline format: <h2>Name - https://link...</h2>
        if line.startswith('<h2>') and ' - http' in line:
            parts_line = line.replace('<h2>', '').replace('</h2>', '').strip()
            name_part = parts_line.split(' - ')[0].strip()
            link_part = parts_line.split(' - ')[1].strip()
            if not in_list:
                new_lines.append('<ul>')
                in_list = True
            new_lines.append(f'<li><a href="{link_part}" target="_blank">{name_part}</a></li>')
            i += 1
            continue

        if in_list:
            new_lines.append('</ul>')
            in_list = False
        new_lines.append(line)
        i += 1

    if in_list:
        new_lines.append('</ul>')

    with open('inv_design_tools.php', 'w', encoding='utf-8') as f:
        f.write(header + '\n'.join(new_lines) + footer)
    print('Fixed inv_design_tools.php')

def fix_slicing_tools():
    with open('inv_slicing_tools.php', 'r', encoding='utf-8') as f:
        content = f.read()

    parts = content.split('legal_render_head("Guide & Inventory");\n?>\n')
    if len(parts) < 2: return
    
    header = parts[0] + 'legal_render_head("Guide & Inventory");\n?>\n'
    footer_split = parts[1].split('\n<?php\nlegal_render_foot();')
    body = footer_split[0]
    footer = '\n<?php\nlegal_render_foot();' + footer_split[1]

    lines = body.split('\n')
    new_lines = []
    
    i = 0
    in_list = False
    
    while i < len(lines):
        line = lines[i].strip()
        if not line:
            i += 1
            continue
            
        if line == '<h2>Slicing & Print Preparation Tools Inventory</h2>':
            if in_list:
                new_lines.append('</ul>')
                in_list = False
            new_lines.append('<h2>Slicing & Print Preparation Tools Inventory</h2>')
            i += 1
            continue
            
        if re.match(r'^<h2>\d+\.\s', line):
            if in_list:
                new_lines.append('</ul>')
                in_list = False
            new_lines.append(line)
            i += 1
            continue

        # Inline format: <h2>Name - https://link...</h2>
        if line.startswith('<h2>') and ' - http' in line:
            parts_line = line.replace('<h2>', '').replace('</h2>', '').strip()
            name_part = parts_line.split(' - ')[0].strip()
            link_part = parts_line.split(' - ')[1].strip()
            if not in_list:
                new_lines.append('<ul>')
                in_list = True
            new_lines.append(f'<li><a href="{link_part}" target="_blank">{name_part}</a></li>')
            i += 1
            continue

        # Pair format: <h2>Name</h2> followed by <h2>https://link...</h2>
        if line.startswith('<h2>') and 'http' not in line:
            name = line.replace('<h2>', '').replace('</h2>', '').strip()
            if i + 1 < len(lines) and lines[i+1].startswith('<h2>http'):
                link = lines[i+1].replace('<h2>', '').replace('</h2>', '').strip()
                if not in_list:
                    new_lines.append('<ul>')
                    in_list = True
                new_lines.append(f'<li><a href="{link}" target="_blank">{name}</a></li>')
                i += 2
                continue

        if in_list:
            new_lines.append('</ul>')
            in_list = False
        new_lines.append(line)
        i += 1

    if in_list:
        new_lines.append('</ul>')

    with open('inv_slicing_tools.php', 'w', encoding='utf-8') as f:
        f.write(header + '\n'.join(new_lines) + footer)
    print('Fixed inv_slicing_tools.php')

fix_design_tools()
fix_slicing_tools()
