import os, re
files = ['guide_3d_basics.html', 'guide_laser.html', 'guide_cnc.html', 'inv_choose_tool.html', 'inv_learn_software.html', 'inv_prepare_materials.html']

for f in files:
    if not os.path.exists(f): continue
    with open(f, 'r', encoding='utf-8') as file:
        content = file.read()
    
    title_match = re.search(r'<title>(.*?)</title>', content)
    title = title_match.group(1).split(' - ')[0] if title_match else 'Guide'
    
    match = re.search(r'<p>\s*(.*?)\s*</p>', content, re.DOTALL)
    if not match: continue
    
    text = match.group(1)
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
            
        if line.startswith('??') or line.startswith('??') or line.startswith('?') or line.startswith('?'):
            if not in_list:
                formatted.append('<ul>')
                in_list = True
            clean_line = line.lstrip(' ?\t\r\n')
            formatted.append(f'<li>{clean_line}</li>')
        elif line.endswith('?') or (len(line.split()) <= 6 and not line.endswith('.')):
            if in_list:
                formatted.append('</ul>')
                in_list = False
            formatted.append(f'<h2>{line}</h2>')
        else:
            if in_list:
                formatted.append('</ul>')
                in_list = False
            formatted.append(f'<p>{line}</p>')
            
    if in_list:
        formatted.append('</ul>')
        
    html_out = '<?php\nrequire_once __DIR__ . "/config.php";\nrequire_once __DIR__ . "/includes/legal.php";\nlegal_render_head("' + title + '");\n?>\n'
    html_out += '\n'.join(formatted)
    html_out += '\n<?php\nlegal_render_foot();\n?>\n'
    
    out_f = f.replace('.html', '.php')
    with open(out_f, 'w', encoding='utf-8') as out:
        out.write(html_out)
    print(f'Processed {f} -> {out_f}')
