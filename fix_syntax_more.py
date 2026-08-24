import os
import re

for folder in ['tests', 'resources', 'database', 'routes']:
    for root, dirs, files in os.walk(folder):
        for file in files:
            if file.endswith('.php'):
                path = os.path.join(root, file)
                with open(path, 'r', encoding='utf-8') as f:
                    content = f.read()
                
                new_content = re.sub(r'(?<!\()new\s+([A-Za-z0-9_\\]+)\(([^)]*)\)->', r'(new \1(\2))->', content)
                
                if new_content != content:
                    with open(path, 'w', encoding='utf-8') as f:
                        f.write(new_content)
                    print(f"Fixed {path}")
