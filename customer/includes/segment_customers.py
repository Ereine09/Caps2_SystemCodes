import pandas as pd
import mysql.connector
from sklearn.preprocessing import StandardScaler
from sklearn.cluster import KMeans
import warnings

# Suppress warnings from sklearn about feature names
warnings.filterwarnings('ignore', category=UserWarning, module='sklearn')

def connect_to_database():
    """ Connect to the MySQL database using credentials. """
    try:
        # These credentials should match your app/config/config.php
        connection = mysql.connector.connect(
            host="127.0.0.1",
            user="root",
            password="",
            database="capstone_db",
            port=3306
        )
        print("Successfully connected to the database.")
        return connection
    except mysql.connector.Error as e:
        print(f"Error connecting to MySQL Database: {e}")
        return None

def fetch_customer_data(connection):
    """ Fetch customer data for segmentation. """
    query = """
    SELECT 
        c.id, 
        c.loyalty_points,
        COUNT(lt.id) as transaction_count,
        DATEDIFF(NOW(), MAX(lt.created_at)) as days_since_last_activity
    FROM customers c
    LEFT JOIN loyalty_transactions lt ON c.id = lt.customer_id
    GROUP BY c.id
    HAVING COUNT(lt.id) > 0;
    """
    df = pd.read_sql(query, connection)
    print(f"Fetched {len(df)} customers with transaction history.")
    return df

def perform_segmentation(df):
    """ Perform customer segmentation using K-Means clustering. """
    if df.empty or len(df) < 3:
        print("Not enough data to perform segmentation.")
        return None

    # Prepare the data for the model
    features = df[['loyalty_points', 'transaction_count', 'days_since_last_activity']].fillna(0)
    
    # Scale the features so they have equal importance
    scaler = StandardScaler()
    scaled_features = scaler.fit_transform(features)

    # Use K-Means to create 3 clusters (segments)
    kmeans = KMeans(n_clusters=3, random_state=42, n_init=10)
    df['segment'] = kmeans.fit_predict(scaled_features)

    # Rename segments for clarity based on loyalty points
    # Find the cluster center with the highest loyalty points
    cluster_centers = pd.DataFrame(scaler.inverse_transform(kmeans.cluster_centers_), columns=features.columns)
    
    high_value_cluster = cluster_centers['loyalty_points'].idxmax()
    low_value_cluster = cluster_centers['loyalty_points'].idxmin()
    
    # Create a mapping from cluster index to a meaningful name
    segment_map = {
        high_value_cluster: 'High-Value',
        low_value_cluster: 'Low-Value'
    }
    # The remaining cluster is 'Mid-Value'
    mid_value_cluster = [c for c in [0, 1, 2] if c not in segment_map][0]
    segment_map[mid_value_cluster] = 'Mid-Value'
    
    df['segment_name'] = df['segment'].map(segment_map)
    
    print("Segmentation complete. Segments found:")
    print(df['segment_name'].value_counts())
    
    return df[['id', 'segment_name']]

def update_database(connection, segmented_df):
    """ Update the customers table with the new segment information. """
    if segmented_df is None:
        print("No segmentation data to update.")
        return

    cursor = connection.cursor()
    
    # First, ensure the column exists
    try:
        cursor.execute("ALTER TABLE customers ADD COLUMN ml_segment VARCHAR(50) NULL")
        print("Added 'ml_segment' column to customers table.")
    except mysql.connector.Error as e:
        if e.errno == 1060: # Error code for 'Duplicate column name'
            print("'ml_segment' column already exists.")
        else:
            raise

    # Update each customer record
    for index, row in segmented_df.iterrows():
        cursor.execute("UPDATE customers SET ml_segment = %s WHERE id = %s", (row['segment_name'], row['id']))
    
    connection.commit()
    print(f"Successfully updated {len(segmented_df)} customer records in the database.")
    cursor.close()

if __name__ == "__main__":
    db_connection = connect_to_database()
    if db_connection:
        customer_df = fetch_customer_data(db_connection)
        segmented_customers = perform_segmentation(customer_df)
        update_database(db_connection, segmented_customers)
        db_connection.close()
        print("Process finished.")