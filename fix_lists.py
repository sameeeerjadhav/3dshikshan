import re

def fix_design_tools():
    with open('inv_design_tools.php', 'r', encoding='utf-8') as f:
        content = f.read()

    # Find the main content
    parts = content.split('legal_render_head("Guide & Inventory");\n?>\n')
    if len(parts) < 2: return
    
    header = parts[0] + 'legal_render_head("Guide & Inventory");\n?>\n'
    footer_split = parts[1].split('\n<?php\nlegal_render_foot();')
    body = footer_split[0]
    footer = '\n<?php\nlegal_render_foot();' + footer_split[1]

    # Process body
    lines = body.split('\n')
    new_lines = []
    
    i = 0
    in_list = False
    
    while i < len(lines):
        line = lines[i].strip()
        if not line:
            i += 1
            continue
            
        if line.startswith('<h2>'):
            if in_list:
                new_lines.append('</ul>')
                in_list = False
            new_lines.append(line)
            i += 1
            continue
            
        if line == '<p>Design Support Tools & Digital Inventory</p>':
            new_lines.append('<h2>Design Support Tools & Digital Inventory</h2>')
            i += 1
            continue

        # Look ahead for a link (pair format)
        if line.startswith('<p>') and not line.startswith('<p><a'):
            name = line.replace('<p>', '').replace('</p>', '').strip()
            if i + 1 < len(lines) and lines[i+1].startswith('<p><a href='):
                link_match = re.search(r'href="([^"]+)"', lines[i+1])
                if link_match:
                    if not in_list:
                        new_lines.append('<ul>')
                        in_list = True
                    new_lines.append(f'<li><a href="{link_match.group(1)}" target="_blank">{name}</a></li>')
                    i += 2
                    continue
        
        # If it didn't match the pair, just append (inline dash format)
        if line.startswith('<p>') and '-' in line and '<a href' in line:
            name_part = line.split('-')[0].replace('<p>', '').strip()
            link_match = re.search(r'href="([^"]+)"', line)
            if link_match:
                if not in_list:
                    new_lines.append('<ul>')
                    in_list = True
                new_lines.append(f'<li><a href="{link_match.group(1)}" target="_blank">{name_part}</a></li>')
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
    
    in_list = False
    
    for line in lines:
        line = line.strip()
        if not line: continue
        
        if line.startswith('<h2>'):
            if in_list:
                new_lines.append('</ul>')
                in_list = False
            new_lines.append(line)
            continue
            
        if line == '<p>Slicing & Print Preparation Tools Inventory</p>':
            new_lines.append('<h2>Slicing & Print Preparation Tools Inventory</h2>')
            continue

        if line.startswith('<p>') and ' - ' in line and '<a href' in line:
            name_part = line.split(' - ')[0].replace('<p>', '').strip()
            link_match = re.search(r'href="([^"]+)"', line)
            if link_match:
                if not in_list:
                    new_lines.append('<ul>')
                    in_list = True
                new_lines.append(f'<li><a href="{link_match.group(1)}" target="_blank">{name_part}</a></li>')
                continue
                
        # What if it's <p>Name</p> \n <p>Link</p> ? (Sometimes tools can be formatted like this)
        
        if in_list:
            new_lines.append('</ul>')
            in_list = False
        new_lines.append(line)

    if in_list:
        new_lines.append('</ul>')

    with open('inv_slicing_tools.php', 'w', encoding='utf-8') as f:
        f.write(header + '\n'.join(new_lines) + footer)
    print('Fixed inv_slicing_tools.php')

fix_design_tools()
fix_slicing_tools()
