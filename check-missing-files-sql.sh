#!/bin/bash
# Quick SQL diagnostic to check asset records for "missing" files
# This shows what the Craft database says about files that exist in bucket

echo "================================================================="
echo " Missing Files - Database Check"
echo "================================================================="
echo ""
echo "Checking asset database records for files reported as missing..."
echo ""

# Sample missing files (update this list with your actual missing files)
MISSING_FILES=(
    "2025-26_UrbLab_Nov_Alexandre.jpg"
    "Wakefield-bridge-and-dam-1.JPG"
    "Westboro-beach-drone.JPG"
    "IMG_1880.jpg"
    "pont-alexandra-bridge.jpg"
    "Echo-6.jpg"
    "054A4456_LR.jpg"
)

for filename in "${MISSING_FILES[@]}"; do
    echo "─────────────────────────────────────────────────────────────────"
    echo "Searching for: $filename"
    echo "─────────────────────────────────────────────────────────────────"

    # Query to find asset record
    mysql -u root -p"$DB_PASSWORD" -h "$DB_HOST" "$DB_NAME" << EOF
SELECT
    a.id AS asset_id,
    a.filename,
    a.volumeId AS volume_id,
    v.name AS volume_name,
    v.handle AS volume_handle,
    f.path AS folder_path,
    CONCAT(COALESCE(f.path, ''), IF(f.path IS NULL OR f.path = '', '', '/'), a.filename) AS full_path
FROM assets a
LEFT JOIN volumes v ON a.volumeId = v.id
LEFT JOIN volumefolders f ON a.folderId = f.id
WHERE a.filename = '$filename';
EOF

    echo ""
done

echo "================================================================="
echo " Analysis"
echo "================================================================="
echo ""
echo "For each file, check:"
echo "  1. Does an asset record exist? (asset_id should be shown)"
echo "  2. What volume is it assigned to? (volume_name)"
echo "  3. What is the full_path in the database?"
echo "  4. Does that path match where the file actually exists?"
echo ""
echo "Common Issues:"
echo "  - No asset_id: File exists but no database record (orphaned)"
echo "  - Wrong volume: Asset assigned to wrong volume"
echo "  - Wrong folder_path: Asset has incorrect folder in database"
echo ""
echo "To check your actual filesystem structure, run:"
echo "  aws s3 ls s3://YOUR-BUCKET/medias/images/ | grep FILENAME"
echo ""
