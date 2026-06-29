import re

def process_file(in_file, out_file):
    with open(in_file, 'r', encoding='utf-8') as f:
        lines = f.read().split('\n')
        
    paragraphs = []
    current_para = []
    
    def is_heading(l):
        # A heading usually starts with a number, an emoji, or is short
        return re.match(r'^\d+\.', l) or re.match(r'^[\U00010000-\U0010ffff]', l) or (len(l.split()) < 5 and not l.endswith('.')) or l.startswith('Step') or l.startswith('Problem') or l.startswith('Symptoms') or l.startswith('Causes') or l.startswith('Solutions')

    def is_list_item(l):
        return l.startswith('✅') or l.startswith('❌') or l.startswith('•') or l.startswith('❓') or l.startswith('-')

    for line in lines:
        line = line.strip()
        if not line:
            if current_para:
                paragraphs.append(' '.join(current_para))
                current_para = []
            continue
            
        if is_heading(line) or is_list_item(line):
            if current_para:
                paragraphs.append(' '.join(current_para))
                current_para = []
            paragraphs.append(line)
        else:
            current_para.append(line)

    if current_para:
        paragraphs.append(' '.join(current_para))
        
    # Now format paragraphs to HTML
    html = []
    in_list = False
    
    for p in paragraphs:
        if is_list_item(p):
            if not in_list:
                html.append('<ul>')
                in_list = True
            clean_p = re.sub(r'^[✅❌•❓\-]\s*', '', p)
            html.append(f'<li>{clean_p}</li>')
        else:
            if in_list:
                html.append('</ul>')
                in_list = False
            
            if is_heading(p):
                html.append(f'<h2>{p}</h2>')
            else:
                html.append(f'<p>{p}</p>')
                
    if in_list:
        html.append('</ul>')
        
    out = '<?php\nrequire_once __DIR__ . "/config.php";\nrequire_once __DIR__ . "/includes/legal.php";\nlegal_render_head("Guide & Inventory");\n?>\n'
    out += '\n'.join(html)
    out += '\n<?php\nlegal_render_foot();\n?>\n'
    
    with open(out_file, 'w', encoding='utf-8') as f:
        f.write(out)

process_file('raw_hardware.txt', 'guide_hardware.php')
process_file('raw_process.txt', 'guide_process.php')
