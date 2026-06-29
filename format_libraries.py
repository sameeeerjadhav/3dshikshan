import re

def process_table(lines):
    # lines contains a mix of headings and table data
    html = []
    i = 0
    while i < len(lines):
        line = lines[i].strip()
        if not line:
            i += 1
            continue
            
        if "→" in line:
            html.append(f'<li>{line}</li>')
            i += 1
            continue

        if line == 'Platform':
            # Identify columns
            if i + 1 < len(lines) and lines[i+1].strip() == 'Categories':
                cols = 3
                html.append('<div style="overflow-x:auto; margin-bottom:20px;"><table class="table" style="width:100%; border-collapse:collapse; text-align:left;">')
                html.append('<tr style="border-bottom: 2px solid var(--border);">')
                html.append('<th style="padding:10px;">Platform</th>')
                html.append('<th style="padding:10px;">Categories</th>')
                html.append('<th style="padding:10px;">Link</th>')
                html.append('</tr>')
                i += 3
            elif i + 1 < len(lines) and lines[i+1].strip() == 'Link':
                cols = 2
                html.append('<div style="overflow-x:auto; margin-bottom:20px;"><table class="table" style="width:100%; border-collapse:collapse; text-align:left;">')
                html.append('<tr style="border-bottom: 2px solid var(--border);">')
                html.append('<th style="padding:10px;">Platform</th>')
                html.append('<th style="padding:10px;">Link</th>')
                html.append('</tr>')
                i += 2
            else:
                html.append(f'<p>{line}</p>')
                i += 1
                continue
                
            # Now parse rows
            while i < len(lines):
                if not lines[i].strip():
                    i += 1
                    continue
                # If we encounter an all-caps heading or "QUICK ACCESS", table ends
                if lines[i].strip().isupper() or lines[i].strip().startswith('QUICK ACCESS'):
                    break
                
                html.append('<tr style="border-bottom: 1px solid var(--border);">')
                if cols == 3 and i + 2 < len(lines):
                    html.append(f'<td style="padding:10px;">{lines[i].strip()}</td>')
                    html.append(f'<td style="padding:10px;">{lines[i+1].strip()}</td>')
                    link = lines[i+2].strip()
                    html.append(f'<td style="padding:10px;"><a href="{link}" target="_blank">Visit</a></td>')
                    i += 3
                elif cols == 2 and i + 1 < len(lines):
                    html.append(f'<td style="padding:10px;">{lines[i].strip()}</td>')
                    link = lines[i+1].strip()
                    html.append(f'<td style="padding:10px;"><a href="{link}" target="_blank">Visit</a></td>')
                    i += 2
                else:
                    break
                html.append('</tr>')
                
            html.append('</table></div>')
        else:
            if line.isupper() or line.startswith('QUICK ACCESS'):
                html.append(f'<h2 style="margin-top:30px;">{line}</h2>')
            else:
                html.append(f'<p>{line}</p>')
            i += 1
            
    return '\n'.join(html)

filepath = 'inv_libraries.php'
with open(filepath, 'r', encoding='utf-8') as f:
    content = f.read()

match = re.search(r'<p>(.*?)</p>', content, re.DOTALL)
if match:
    text = match.group(1)
    html = process_table(text.split('\n'))
    
    out = '<?php\nrequire_once __DIR__ . "/config.php";\nrequire_once __DIR__ . "/includes/legal.php";\nlegal_render_head("Guide & Inventory");\n?>\n'
    out += html
    out += '\n<?php\nlegal_render_foot();\n?>\n'
    
    with open(filepath, 'w', encoding='utf-8') as f:
        f.write(out)
    print(f"Processed {filepath}")
