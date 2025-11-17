#!/usr/bin/env php
<?php
/**
 * NOTE: This diagnostic is DEPRECATED
 *
 * Use the correct diagnostic instead:
 *   php diagnose-asset-file-mismatch.php
 *
 * Or read the updated troubleshooting guide:
 *   TROUBLESHOOTING-MISSING-FILES-V2.md
 */

echo "\n";
echo "=================================================================\n";
echo " DEPRECATED DIAGNOSTIC\n";
echo "=================================================================\n\n";

echo "This diagnostic is based on an incorrect assumption.\n\n";

echo "Please use the CORRECT diagnostic:\n";
echo "  php diagnose-asset-file-mismatch.php\n\n";

echo "Or read the updated guide:\n";
echo "  TROUBLESHOOTING-MISSING-FILES-V2.md\n\n";

echo "=================================================================\n";
exit(0);

// OLD CODE BELOW (for reference)
// Define the missing files (sample from user's list)
$missingFiles = [
    '2025-26_UrbLab_Nov_Alexandre.jpg',
    'Wakefield-bridge-and-dam-1.JPG',
    'Westboro-beach-drone.JPG',
    'IMG_1880.jpg',
    'pont-alexandra-bridge.jpg',
    'Echo-6.jpg',
    '054A4456_LR.jpg',
    // Add more as needed
];

// The actual paths where files exist in the bucket (corrected)
$bucketPaths = [
    '/medias/images/2025-26_UrbLab_Nov_Alexandre.jpg',
    '/medias/images/originals/2025-26_UrbLab_Nov_Alexandre.jpg',
    '/medias/Wakefield-bridge-and-dam-1.JPG',
    '/medias/images/Wakefield-bridge-and-dam-1.JPG',
    '/medias/images/originals/Wakefield-bridge-and-dam-1.JPG',
    '/medias/originals/Wakefield-bridge-and-dam-1.JPG',
];

echo "=================================================================\n";
echo " DIAGNOSTIC: Missing Files Investigation\n";
echo "=================================================================\n\n";

echo "ISSUE ANALYSIS:\n";
echo "---------------\n";
echo "Files exist in bucket but migration reports them as missing.\n\n";

echo "OBSERVATION 1: Bucket Structure\n";
echo "  All files in bucket have a common root prefix: '/ncc-website-2/'\n";
echo "  Examples:\n";
foreach (array_slice($bucketPaths, 0, 5) as $path) {
    echo "    - $path\n";
}
echo "\n";

echo "OBSERVATION 2: File Distribution\n";
echo "  Files exist in multiple locations:\n";
echo "    - /ncc-website-2/FILENAME (bucket root)\n";
echo "    - /ncc-website-2/images/FILENAME (images folder)\n";
echo "    - /ncc-website-2/images/originals/FILENAME (originals subfolder)\n";
echo "    - /ncc-website-2/originals/FILENAME (originals at bucket level)\n";
echo "    - /ncc-website-2/documents/FILENAME (documents folder)\n";
echo "\n";

echo "POSSIBLE CAUSES:\n";
echo "----------------\n";
echo "1. ❌ Volume subfolder misconfiguration\n";
echo "   The Craft volume's subfolder setting may not include 'ncc-website-2/'\n";
echo "   Expected subfolder: 'ncc-website-2/images'\n";
echo "   Actual subfolder:   'images' (missing the root prefix)\n\n";

echo "2. ❌ Filesystem scanning starting from wrong location\n";
echo "   The filesystem scanner might be looking at the bucket root\n";
echo "   without the 'ncc-website-2/' prefix.\n\n";

echo "3. ❌ Multiple volumes pointing to different subfolders\n";
echo "   If you have 'images' and 'documents' volumes, they need to be\n";
echo "   configured with the correct subfolders:\n";
echo "     - images volume subfolder: 'ncc-website-2/images'\n";
echo "     - documents volume subfolder: 'ncc-website-2/documents'\n\n";

echo "SOLUTION STEPS:\n";
echo "---------------\n";
echo "1. Check your Craft CMS volume configuration:\n";
echo "   - Go to: Craft CP → Settings → Assets → Volumes\n";
echo "   - For each volume (images, documents, etc.), click to edit\n";
echo "   - Check the 'Filesystem' setting and note the filesystem handle\n\n";

echo "2. Check your filesystem configuration:\n";
echo "   - Go to: Craft CP → Settings → Assets → Filesystems\n";
echo "   - Find the filesystem used by your volumes\n";
echo "   - Check the 'Subfolder' or 'Base Path' setting\n";
echo "   - It should include 'ncc-website-2/' at the beginning\n\n";

echo "3. Option A: Update Craft volume configuration (RECOMMENDED)\n";
echo "   Update the filesystem subfolder to include 'ncc-website-2/'\n";
echo "   Example:\n";
echo "     Current:  'images'\n";
echo "     Updated:  'ncc-website-2/images'\n\n";

echo "4. Option B: Add source volume configuration\n";
echo "   If the 'images' volume should scan multiple locations,\n";
echo "   you might need to add an 'optimisedImages' or additional\n";
echo "   source volume that points to 'ncc-website-2/images/originals'\n\n";

echo "VERIFICATION:\n";
echo "-------------\n";
echo "After making changes, run:\n";
echo "  ./craft s3-spaces-migration/migration-diag/check-volumes\n\n";

echo "The output should show:\n";
echo "  Volume: images\n";
echo "  Filesystem: images (AWS S3)\n";
echo "  Subfolder: 'ncc-website-2/images'  ← This is key!\n";
echo "  Files found: [should be > 0]\n\n";

echo "ENVIRONMENT VARIABLE CHECK:\n";
echo "----------------------------\n";
echo "If using environment variables for subfolders, check your .env file:\n";
echo "  # For AWS source volumes\n";
echo "  AWS_SOURCE_SUBFOLDER_IMAGES='ncc-website-2/images'\n";
echo "  AWS_SOURCE_SUBFOLDER_DOCUMENTS='ncc-website-2/documents'\n";
echo "  \n";
echo "  # For DO target volumes  \n";
echo "  DO_S3_SUBFOLDER_IMAGES='images'  # or '' for root\n";
echo "  DO_S3_SUBFOLDER_DOCUMENTS='documents'\n\n";

echo "=================================================================\n";
echo " Next Steps:\n";
echo "=================================================================\n";
echo "1. Verify volume subfolder configuration in Craft CP\n";
echo "2. Update subfolder to include 'ncc-website-2/' prefix\n";
echo "3. Re-run migration to rebuild file inventory\n";
echo "4. Check if missing files are now found\n\n";
