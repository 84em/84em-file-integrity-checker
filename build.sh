#!/bin/bash
#
# Build script for 84EM File Integrity Checker WordPress Plugin
# Creates a production-ready ZIP file for distribution
#
# Usage: 
#   ./build.sh                    # Standard build with locked dependencies
#   UPDATE_DEPS=true ./build.sh  # Build with updated dependencies
#
# Environment Variables:
#   UPDATE_DEPS=true  - Updates all production dependencies to latest versions
#                       before building (default: false, uses composer.lock)
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

# Pre-build checks
echo -e "\n${YELLOW}→ Running pre-build checks...${NC}"

# Check if composer.lock exists and has Action Scheduler
if [ -f "composer.lock" ]; then
    if ! grep -q "woocommerce/action-scheduler" composer.lock; then
        echo -e "${YELLOW}Warning: Action Scheduler not found in composer.lock${NC}"
        echo -e "${YELLOW}Run 'composer update woocommerce/action-scheduler' to update lock file${NC}"
    fi
else
    echo -e "${YELLOW}Warning: composer.lock not found. Build will create one.${NC}"
fi

# Check if Action Scheduler is in require (not require-dev)
if grep -A 5 '"require-dev"' composer.json | grep -q '"woocommerce/action-scheduler"'; then
    echo -e "${RED}Error: Action Scheduler is in 'require-dev' section of composer.json${NC}"
    echo -e "${YELLOW}It should be in the 'require' section for production use${NC}"
    exit 1
fi

if ! grep -A 10 '"require"' composer.json | grep -q '"woocommerce/action-scheduler"'; then
    echo -e "${RED}Error: Action Scheduler not found in 'require' section of composer.json${NC}"
    exit 1
fi

# Step 1: Clean up previous builds
echo -e "\n${YELLOW}→ Cleaning previous builds...${NC}"
rm -rf ${BUILD_DIR}
rm -rf ${DIST_DIR}
mkdir -p ${BUILD_DIR}
mkdir -p ${DIST_DIR}

# Step 2: Create build directory structure
echo -e "${YELLOW}→ Creating build directory...${NC}"
mkdir -p ${BUILD_DIR}/${PLUGIN_SLUG}

# Step 3: Minify CSS and JS files
echo -e "${YELLOW}→ Minifying CSS and JavaScript files...${NC}"

# Check if npm is available
if command -v npm &> /dev/null; then
    # Install dependencies if needed
    if [ ! -d "node_modules" ]; then
        echo -e "${YELLOW}  Installing npm dependencies...${NC}"
        npm install --silent
    fi
    
    # Run minification using npx to ensure tools are available
    echo -e "${YELLOW}  Running minification...${NC}"
    npx terser assets/js/admin.js -o assets/js/admin.min.js -c -m --source-map
    npx terser assets/js/modal.js -o assets/js/modal.min.js -c -m --source-map
    npx clean-css-cli -o assets/css/admin.min.css assets/css/admin.css --source-map
    
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}  ✅ Assets minified successfully${NC}"
    else
        echo -e "${YELLOW}  ⚠️  Minification failed, using non-minified files${NC}"
    fi
else
    echo -e "${YELLOW}  ⚠️  npm not found, skipping minification${NC}"
fi

# Step 4: Copy plugin files (excluding development files)
echo -e "${YELLOW}→ Copying plugin files...${NC}"

# Copy PHP source files
cp -r src ${BUILD_DIR}/${PLUGIN_SLUG}/

# Copy view templates
cp -r views ${BUILD_DIR}/${PLUGIN_SLUG}/

# Copy assets (CSS, JS, images)
if [ -d "assets" ]; then
    mkdir -p ${BUILD_DIR}/${PLUGIN_SLUG}/assets/css
    mkdir -p ${BUILD_DIR}/${PLUGIN_SLUG}/assets/js
    mkdir -p ${BUILD_DIR}/${PLUGIN_SLUG}/assets/images
    
    # Copy minified CSS files (or original if minified doesn't exist)
    if [ -f "assets/css/admin.min.css" ]; then
        cp assets/css/admin.min.css ${BUILD_DIR}/${PLUGIN_SLUG}/assets/css/
        cp assets/css/admin.min.css.map ${BUILD_DIR}/${PLUGIN_SLUG}/assets/css/ 2>/dev/null || true
    else
        cp assets/css/admin.css ${BUILD_DIR}/${PLUGIN_SLUG}/assets/css/
    fi
    
    # Copy minified JS files (or original if minified doesn't exist)
    if [ -f "assets/js/admin.min.js" ]; then
        cp assets/js/admin.min.js ${BUILD_DIR}/${PLUGIN_SLUG}/assets/js/
        cp assets/js/admin.min.js.map ${BUILD_DIR}/${PLUGIN_SLUG}/assets/js/ 2>/dev/null || true
    else
        cp assets/js/admin.js ${BUILD_DIR}/${PLUGIN_SLUG}/assets/js/
    fi
    
    if [ -f "assets/js/modal.min.js" ]; then
        cp assets/js/modal.min.js ${BUILD_DIR}/${PLUGIN_SLUG}/assets/js/
        cp assets/js/modal.min.js.map ${BUILD_DIR}/${PLUGIN_SLUG}/assets/js/ 2>/dev/null || true
    else
        cp assets/js/modal.js ${BUILD_DIR}/${PLUGIN_SLUG}/assets/js/
    fi
    
    # Copy images if they exist
    if [ -d "assets/images" ]; then
        cp -r assets/images/* ${BUILD_DIR}/${PLUGIN_SLUG}/assets/images/ 2>/dev/null || true
    fi
fi

# Copy main plugin file
cp ${PLUGIN_SLUG}.php ${BUILD_DIR}/${PLUGIN_SLUG}/

# Copy documentation files
cp LICENSE ${BUILD_DIR}/${PLUGIN_SLUG}/
cp changelog.txt ${BUILD_DIR}/${PLUGIN_SLUG}/

# Copy composer.json for dependency installation
cp composer.json ${BUILD_DIR}/${PLUGIN_SLUG}/
[ -f "composer.lock" ] && cp composer.lock ${BUILD_DIR}/${PLUGIN_SLUG}/

# Step 5: Install production dependencies
echo -e "${YELLOW}→ Installing production dependencies...${NC}"
cd ${BUILD_DIR}/${PLUGIN_SLUG}

# Check if composer is available
if ! command -v composer &> /dev/null; then
    echo -e "${RED}Error: Composer is not installed or not in PATH${NC}"
    exit 1
fi

# Verify composer.json has Action Scheduler as a dependency
if ! grep -q "woocommerce/action-scheduler" composer.json; then
    echo -e "${RED}Error: Action Scheduler is not defined as a dependency in composer.json${NC}"
    echo -e "${YELLOW}Adding Action Scheduler to composer.json...${NC}"
    composer require woocommerce/action-scheduler:^3.7 --no-interaction --no-scripts
fi

# Option to update dependencies to latest versions (can be controlled by environment variable)
if [ "${UPDATE_DEPS}" = "true" ]; then
    echo -e "${YELLOW}→ Updating dependencies to latest versions...${NC}"
    composer update --no-dev --optimize-autoloader --no-interaction --no-scripts --prefer-dist
else
    # Install from lock file (default behavior for consistency)
    echo -e "${YELLOW}→ Installing from composer.lock...${NC}"
    composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --prefer-dist
fi

# Verify Action Scheduler was installed
if [ ! -d "vendor/woocommerce/action-scheduler" ]; then
    echo -e "${RED}Error: Action Scheduler was not installed!${NC}"
    echo -e "${YELLOW}Attempting to install Action Scheduler directly...${NC}"
    composer require woocommerce/action-scheduler:^3.7 --no-dev --optimize-autoloader --no-interaction --no-scripts
    
    # Check again
    if [ ! -d "vendor/woocommerce/action-scheduler" ]; then
        echo -e "${RED}Fatal: Failed to install Action Scheduler. Build cannot continue.${NC}"
        exit 1
    fi
fi

# Verify Action Scheduler main file exists
if [ ! -f "vendor/woocommerce/action-scheduler/action-scheduler.php" ]; then
    echo -e "${RED}Error: Action Scheduler main file not found${NC}"
    exit 1
fi

echo -e "${GREEN}✅ Action Scheduler installed successfully${NC}"

# Step 6: Clean up composer files (but keep autoloader!)
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

# Step 7: Create the ZIP file
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

# Step 8: Clean up build directory
echo -e "${YELLOW}→ Cleaning up temporary files...${NC}"
rm -rf ${BUILD_DIR}

# Step 9: Display results
echo -e "\n${GREEN}========================================${NC}"
echo -e "${GREEN}✅ Build completed successfully!${NC}"
echo -e "${GREEN}========================================${NC}"
echo -e "📦 Package: ${DIST_DIR}/${ZIP_NAME}"
echo -e "📏 Size: $(du -h ${DIST_DIR}/${ZIP_NAME} | cut -f1)"
echo -e "📁 Location: $(pwd)/${DIST_DIR}/${ZIP_NAME}"

# Step 10: Verify the package (optional)
echo -e "\n${YELLOW}→ Package contents:${NC}"
unzip -l ${DIST_DIR}/${ZIP_NAME} | head -20
echo "... (showing first 20 files)"

# Step 11: Integrity check
echo -e "\n${YELLOW}→ Verifying package integrity:${NC}"

# Check for required files in the ZIP
REQUIRED_FILES=(
    "${PLUGIN_SLUG}/${PLUGIN_SLUG}.php"
    "${PLUGIN_SLUG}/src/Plugin.php"
    "${PLUGIN_SLUG}/vendor/autoload.php"
    "${PLUGIN_SLUG}/vendor/composer/autoload_real.php"
    "${PLUGIN_SLUG}/LICENSE"
    "${PLUGIN_SLUG}/changelog.txt"
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
