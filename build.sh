#!/bin/bash
#
# Build script for 84EM File Integrity Checker WordPress Plugin
# Creates a production-ready ZIP file for distribution
#
# Usage: ./build.sh
#

set -e  # Exit on error

# Color output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
PLUGIN_SLUG="84em-file-integrity-checker"
VERSION=$(grep "Version:" ${PLUGIN_SLUG}.php | head -1 | awk '{print $3}')
BUILD_DIR="build"
DIST_DIR="dist"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Building ${PLUGIN_SLUG} v${VERSION}${NC}"
echo -e "${GREEN}========================================${NC}"

# Step 1: Clean up previous builds
echo -e "\n${YELLOW}→ Cleaning previous builds...${NC}"
rm -rf ${BUILD_DIR}
rm -rf ${DIST_DIR}
mkdir -p ${BUILD_DIR}
mkdir -p ${DIST_DIR}

# Step 2: Create build directory structure
echo -e "${YELLOW}→ Creating build directory...${NC}"
mkdir -p ${BUILD_DIR}/${PLUGIN_SLUG}

# Step 3: Copy plugin files (excluding development files)
echo -e "${YELLOW}→ Copying plugin files...${NC}"

# Copy PHP source files
cp -r src ${BUILD_DIR}/${PLUGIN_SLUG}/

# Copy view templates
cp -r views ${BUILD_DIR}/${PLUGIN_SLUG}/

# Copy assets (CSS, JS, images)
if [ -d "assets" ]; then
    cp -r assets ${BUILD_DIR}/${PLUGIN_SLUG}/
fi

# Copy main plugin file
cp ${PLUGIN_SLUG}.php ${BUILD_DIR}/${PLUGIN_SLUG}/

# Copy documentation files
cp README.md ${BUILD_DIR}/${PLUGIN_SLUG}/
[ -f "LICENSE" ] && cp LICENSE ${BUILD_DIR}/${PLUGIN_SLUG}/
[ -f "changelog.txt" ] && cp changelog.txt ${BUILD_DIR}/${PLUGIN_SLUG}/

# Copy composer.json for dependency installation
cp composer.json ${BUILD_DIR}/${PLUGIN_SLUG}/
[ -f "composer.lock" ] && cp composer.lock ${BUILD_DIR}/${PLUGIN_SLUG}/

# Step 4: Install production dependencies
echo -e "${YELLOW}→ Installing production dependencies...${NC}"
cd ${BUILD_DIR}/${PLUGIN_SLUG}

# Check if composer is available
if ! command -v composer &> /dev/null; then
    echo -e "${RED}Error: Composer is not installed or not in PATH${NC}"
    exit 1
fi

# Install only production dependencies with optimized autoloader
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --prefer-dist --quiet

# Verify Action Scheduler was installed
if [ ! -d "vendor/woocommerce/action-scheduler" ]; then
    echo -e "${RED}Warning: Action Scheduler was not installed${NC}"
fi

# Step 5: Clean up composer files (but keep autoloader!)
echo -e "${YELLOW}→ Cleaning up build files...${NC}"

# Remove composer files (no longer needed after install)
rm -f composer.json
rm -f composer.lock

# Remove unnecessary files from vendor directory
echo -e "${YELLOW}→ Optimizing vendor directory...${NC}"

# Remove documentation and test files
find vendor -type f -name "*.md" -not -path "*/vendor/composer/*" -delete 2>/dev/null || true
find vendor -type f -name "*.txt" -not -path "*/vendor/composer/*" -delete 2>/dev/null || true
find vendor -type f -name "*.yml" -delete 2>/dev/null || true
find vendor -type f -name "*.yaml" -delete 2>/dev/null || true
find vendor -type f -name "*.dist" -delete 2>/dev/null || true
find vendor -type f -name ".gitignore" -delete 2>/dev/null || true
find vendor -type f -name ".gitattributes" -delete 2>/dev/null || true
find vendor -type f -name "phpunit.xml*" -delete 2>/dev/null || true
find vendor -type f -name "phpcs.xml*" -delete 2>/dev/null || true
find vendor -type f -name "psalm.xml*" -delete 2>/dev/null || true
find vendor -type f -name "infection.json*" -delete 2>/dev/null || true

# Remove test directories
find vendor -type d -name "tests" -exec rm -rf {} + 2>/dev/null || true
find vendor -type d -name "Tests" -exec rm -rf {} + 2>/dev/null || true
find vendor -type d -name "test" -exec rm -rf {} + 2>/dev/null || true
find vendor -type d -name "Test" -exec rm -rf {} + 2>/dev/null || true
find vendor -type d -name "spec" -exec rm -rf {} + 2>/dev/null || true
find vendor -type d -name "features" -exec rm -rf {} + 2>/dev/null || true
find vendor -type d -name "examples" -exec rm -rf {} + 2>/dev/null || true
find vendor -type d -name "docs" -exec rm -rf {} + 2>/dev/null || true
find vendor -type d -name ".git" -exec rm -rf {} + 2>/dev/null || true
find vendor -type d -name ".github" -exec rm -rf {} + 2>/dev/null || true

# Remove composer.json files from vendor packages (but not from composer directory)
find vendor -type f -name "composer.json" -not -path "*/vendor/composer/*" -delete 2>/dev/null || true

# Return to original directory
cd ../..

# Step 6: Create the ZIP file
echo -e "${YELLOW}→ Creating ZIP archive...${NC}"
cd ${BUILD_DIR}

# Create ZIP with maximum compression
zip -r9 ../${DIST_DIR}/${ZIP_NAME} ${PLUGIN_SLUG} \
    -x "*.DS_Store" \
    -x "*/.git/*" \
    -x "*/.gitignore" \
    -x "*/.gitattributes" \
    -x "*/Thumbs.db" \
    > /dev/null

cd ..

# Step 7: Clean up build directory
echo -e "${YELLOW}→ Cleaning up temporary files...${NC}"
rm -rf ${BUILD_DIR}

# Step 8: Display results
echo -e "\n${GREEN}========================================${NC}"
echo -e "${GREEN}✅ Build completed successfully!${NC}"
echo -e "${GREEN}========================================${NC}"
echo -e "📦 Package: ${DIST_DIR}/${ZIP_NAME}"
echo -e "📏 Size: $(du -h ${DIST_DIR}/${ZIP_NAME} | cut -f1)"
echo -e "📁 Location: $(pwd)/${DIST_DIR}/${ZIP_NAME}"

# Step 9: Verify the package (optional)
echo -e "\n${YELLOW}→ Package contents:${NC}"
unzip -l ${DIST_DIR}/${ZIP_NAME} | head -20
echo "... (showing first 20 files)"

# Step 10: Integrity check
echo -e "\n${YELLOW}→ Verifying package integrity:${NC}"

# Check for required files in the ZIP
REQUIRED_FILES=(
    "${PLUGIN_SLUG}/${PLUGIN_SLUG}.php"
    "${PLUGIN_SLUG}/src/Plugin.php"
    "${PLUGIN_SLUG}/vendor/autoload.php"
    "${PLUGIN_SLUG}/vendor/composer/autoload_real.php"
    "${PLUGIN_SLUG}/README.md"
)

MISSING_FILES=0
for file in "${REQUIRED_FILES[@]}"; do
    if unzip -l ${DIST_DIR}/${ZIP_NAME} | grep -q "$file"; then
        echo -e "  ✅ Found: $file"
    else
        echo -e "  ${RED}❌ Missing: $file${NC}"
        MISSING_FILES=$((MISSING_FILES + 1))
    fi
done

# Check for Action Scheduler
if unzip -l ${DIST_DIR}/${ZIP_NAME} | grep -q "vendor/woocommerce/action-scheduler/action-scheduler.php"; then
    echo -e "  ✅ Action Scheduler included"
else
    echo -e "  ${YELLOW}⚠️  Action Scheduler not found (may be loaded by another plugin)${NC}"
fi

if [ $MISSING_FILES -eq 0 ]; then
    echo -e "\n${GREEN}✅ All required files present${NC}"
    echo -e "${GREEN}🎉 Package is ready for distribution!${NC}"
else
    echo -e "\n${RED}⚠️  Warning: Some required files are missing${NC}"
    exit 1
fi

echo -e "\n${YELLOW}Next steps:${NC}"
echo "1. Test the plugin by uploading ${ZIP_NAME} to a fresh WordPress installation"
echo "2. Verify that scheduling features work (Action Scheduler loads correctly)"
echo "3. Run a test scan to ensure all functionality works"
echo "4. Tag the release: git tag v${VERSION}"
echo "5. Create GitHub release and attach the ZIP file"