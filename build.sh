#!/bin/sh
# Packages builder

# Re-create 'packages' folder (Samba cache issues)
if [ -d "packages" ]; then
	rm -Rf ./packages
fi
mkdir ./packages

# Rectify folder permissions
chmod 755 -R rntoolbox/

# Create package
dpkg-deb -b rntoolbox packages

exit 0
