# CSV File Import Feature

## Overview
The CSV File Import feature allows users to upload CSV files containing product data to efficiently update existing nodes or create new nodes in Drupal 8 / 9 / 10. This feature is designed to streamline the process of managing product information in bulk.
## How it Works
1. **Upload CSV File:** Users can upload a CSV file containing product information using the provided form.
2. **CSV Data Mapping:** The feature maps the header values in the CSV file to corresponding fields in Drupal nodes.
3. **Node Creation or Update:**
    - If a node with the same SKU (Unique ID field) already exists, the feature updates the existing node with the new data.
    - If no node with the specified SKU is found, a new node is created with the provided data.
4. **Batch Processing:** To handle large datasets, the feature utilizes Drupal's Batch API for efficient and scalable processing.
Nodes are updated or created in batches to prevent memory issues and ensure smooth processing.
5. **Status Messages:** Upon completion, the user receives status messages indicating the success or failure of the CSV file processing.

## Example Usage
1. Upload a CSV file containing product information.
2. Make sure the CSV file has a header row with column names, including a unique identifier "product_sku."
3. The system processes the CSV file, updating existing nodes or creating new nodes based on SKU values.
4. Users receive status messages indicating the success or failure of the import process.
