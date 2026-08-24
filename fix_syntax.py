import os
import re

for root, dirs, files in os.walk('app'):
    for file in files:
        if file.endswith('.php'):
            path = os.path.join(root, file)
            with open(path, 'r', encoding='utf-8') as f:
                content = f.read()
            
            # replace `new ClassName()->` with `(new ClassName())->`
            # also handles `new ClassName($args)->` 
            new_content = re.sub(r'(?<!\()new\s+([A-Za-z0-9_\\]+)\(([^)]*)\)->', r'(new \1(\2))->', content)
            
            if new_content != content:
                with open(path, 'w', encoding='utf-8') as f:
                    f.write(new_content)
                print(f"Fixed {path}")
