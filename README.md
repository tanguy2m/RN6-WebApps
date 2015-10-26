# ReadyNAS6-apps
Custom applications for ReadyNAS OS6

# Build rntoolbox
1. Copy source files
2. Update permissions
```bash
chmod 755 -R rntoolbox/
```
3. Create package
```bash
dpkg-deb -b rntoolbox rntoolbox_0.0.1_all.deb
```