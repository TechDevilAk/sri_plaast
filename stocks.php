<?php
session_start();
$currentPage = 'stocks';
$pageTitle = 'Stock Levels';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view stock, but only admin can modify
checkRoleAccess(['admin', 'sale']);

// ============================================
// AJAX ENDPOINT - MUST BE AT THE TOP
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_stock' && isset($_GET['id']) && is_numeric($_GET['id'])) {
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set JSON header
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    
    $id = intval($_GET['id']);
    $type = isset($_GET['type']) ? $_GET['type'] : 'category'; // 'category' or 'product'
    
    try {
        if ($type === 'product') {
            // Get product stock details
            $stmt = $conn->prepare("SELECT * FROM product WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Database prepare failed: " . $conn->error);
            }
            
            $stmt->bind_param("i", $id);
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $item = $result->fetch_assoc();
                
                // Get recent purchase history for this product
                $purchase_stmt = $conn->prepare("
                    SELECT 
                        p.purchase_no,
                        p.purchase_date,
                        p.created_at, 
                        pi.qty as quantity, 
                        pi.purchase_price,
                        pi.unit
                    FROM purchase_item pi
                    JOIN purchase p ON pi.purchase_id = p.id
                    WHERE pi.product_id = ?
                    ORDER BY p.created_at DESC
                    LIMIT 5
                ");
                
                if ($purchase_stmt) {
                    $purchase_stmt->bind_param("i", $id);
                    $purchase_stmt->execute();
                    $purchases = $purchase_stmt->get_result();
                    
                    $purchase_list = [];
                    while ($pur = $purchases->fetch_assoc()) {
                        if (empty($pur['purchase_no'])) {
                            $pur['purchase_no'] = 'PUR-' . date('Ymd', strtotime($pur['created_at'])) . '-' . sprintf('%03d', $id);
                        }
                        $purchase_list[] = $pur;
                    }
                    $purchase_stmt->close();
                } else {
                    $purchase_list = [];
                }
                
                echo json_encode([
                    'success' => true, 
                    'item' => $item,
                    'purchases' => $purchase_list,
                    'type' => 'product'
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Product not found']);
            }
            $stmt->close();
        } else {
            // Get category stock details
            $stmt = $conn->prepare("SELECT * FROM category WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Database prepare failed: " . $conn->error);
            }
            
            $stmt->bind_param("i", $id);
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $item = $result->fetch_assoc();
                
                // Get recent purchase history for this category
                $purchase_stmt = $conn->prepare("
                    SELECT 
                        p.purchase_no,
                        p.purchase_date,
                        p.created_at, 
                        pi.qty as quantity, 
                        pi.purchase_price 
                    FROM purchase_item pi
                    JOIN purchase p ON pi.purchase_id = p.id
                    WHERE pi.cat_id = ?
                    ORDER BY p.created_at DESC
                    LIMIT 5
                ");
                
                if ($purchase_stmt) {
                    $purchase_stmt->bind_param("i", $id);
                    $purchase_stmt->execute();
                    $purchases = $purchase_stmt->get_result();
                    
                    $purchase_list = [];
                    while ($pur = $purchases->fetch_assoc()) {
                        if (empty($pur['purchase_no'])) {
                            $pur['purchase_no'] = 'PUR-' . date('Ymd', strtotime($pur['created_at'])) . '-' . sprintf('%03d', $id);
                        }
                        $purchase_list[] = $pur;
                    }
                    $purchase_stmt->close();
                } else {
                    $purchase_list = [];
                }
                
                echo json_encode([
                    'success' => true, 
                    'item' => $item,
                    'purchases' => $purchase_list,
                    'type' => 'category'
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Stock item not found']);
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$success = '';
$error = '';

// Handle category stock update (add/subtract) - Admin only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_category_stock' && isset($_POST['category_id']) && is_numeric($_POST['category_id'])) {
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to update stock.';
    } else {
        $updateId = intval($_POST['category_id']);
        $stock_change = floatval($_POST['stock_change'] ?? 0);
        $operation = $_POST['operation'] ?? 'add';
        $notes = trim($_POST['notes'] ?? '');
        
        if ($stock_change <= 0) {
            $error = "Please enter a valid quantity.";
        } else {
            $current_query = $conn->prepare("SELECT category_name, total_quantity, purchase_price FROM category WHERE id = ?");
            $current_query->bind_param("i", $updateId);
            $current_query->execute();
            $current_result = $current_query->get_result();
            $current_data = $current_result->fetch_assoc();
            
            if (!$current_data) {
                $error = "Category not found.";
            } else {
                $new_quantity = $current_data['total_quantity'];
                $old_quantity = $current_data['total_quantity'];
                
                if ($operation === 'add') {
                    $new_quantity += $stock_change;
                    $operation_text = 'added to';
                    $operation_sign = '+';
                } else {
                    if ($current_data['total_quantity'] < $stock_change) {
                        $error = "Insufficient stock. Current stock: " . number_format($current_data['total_quantity'], 2) . " PCS";
                    } else {
                        $new_quantity -= $stock_change;
                        $operation_text = 'removed from';
                        $operation_sign = '-';
                    }
                }
                
                if (empty($error)) {
                    $stmt = $conn->prepare("UPDATE category SET total_quantity = ? WHERE id = ?");
                    $stmt->bind_param("di", $new_quantity, $updateId);
                    
                    if ($stmt->execute()) {
                        $value_change = $stock_change * $current_data['purchase_price'];
                        
                        $log_desc = "Stock " . $operation_text . " category '" . $current_data['category_name'] . "': " . 
                                   number_format($stock_change, 2) . " PCS (" . $operation_sign . "). " . 
                                   "Previous: " . number_format($old_quantity, 2) . " PCS, " .
                                   "New: " . number_format($new_quantity, 2) . " PCS";
                        
                        $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'stock_update', ?)";
                        $log_stmt = $conn->prepare($log_query);
                        $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                        $log_stmt->execute();
                        $log_stmt->close();
                        
                        $success = "Stock updated successfully. New quantity: " . number_format($new_quantity, 2) . " PCS";
                    } else {
                        $error = "Failed to update stock.";
                    }
                    $stmt->close();
                }
            }
            $current_query->close();
        }
    }
}

// Handle product stock update (add/subtract) - Admin only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_product_stock' && isset($_POST['product_id']) && is_numeric($_POST['product_id'])) {
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to update stock.';
    } else {
        $updateId = intval($_POST['product_id']);
        $stock_change = floatval($_POST['stock_change'] ?? 0);
        $operation = $_POST['operation'] ?? 'add';
        $notes = trim($_POST['notes'] ?? '');
        
        if ($stock_change <= 0) {
            $error = "Please enter a valid quantity.";
        } else {
            $current_query = $conn->prepare("SELECT product_name, stock_quantity, product_type, primary_unit FROM product WHERE id = ?");
            $current_query->bind_param("i", $updateId);
            $current_query->execute();
            $current_result = $current_query->get_result();
            $current_data = $current_result->fetch_assoc();
            
            if (!$current_data) {
                $error = "Product not found.";
            } else {
                $unit = $current_data['primary_unit'] ?? 'pcs';
                $new_quantity = $current_data['stock_quantity'];
                $old_quantity = $current_data['stock_quantity'];
                
                if ($operation === 'add') {
                    $new_quantity += $stock_change;
                    $operation_text = 'added to';
                    $operation_sign = '+';
                } else {
                    if ($current_data['stock_quantity'] < $stock_change) {
                        $error = "Insufficient stock. Current stock: " . number_format($current_data['stock_quantity'], 2) . " " . strtoupper($unit);
                    } else {
                        $new_quantity -= $stock_change;
                        $operation_text = 'removed from';
                        $operation_sign = '-';
                    }
                }
                
                if (empty($error)) {
                    $stmt = $conn->prepare("UPDATE product SET stock_quantity = ? WHERE id = ?");
                    $stmt->bind_param("di", $new_quantity, $updateId);
                    
                    if ($stmt->execute()) {
                        $log_desc = "Stock " . $operation_text . " product '" . $current_data['product_name'] . "': " . 
                                   number_format($stock_change, 2) . " " . strtoupper($unit) . " (" . $operation_sign . "). " . 
                                   "Previous: " . number_format($old_quantity, 2) . " " . strtoupper($unit) . ", " .
                                   "New: " . number_format($new_quantity, 2) . " " . strtoupper($unit);
                        
                        $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'stock_update', ?)";
                        $log_stmt = $conn->prepare($log_query);
                        $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                        $log_stmt->execute();
                        $log_stmt->close();
                        
                        $success = "Stock updated successfully. New quantity: " . number_format($new_quantity, 2) . " " . strtoupper($unit);
                    } else {
                        $error = "Failed to update stock.";
                    }
                    $stmt->close();
                }
            }
            $current_query->close();
        }
    }
}

// Handle edit category stock item - Admin only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_category_stock' && isset($_POST['category_id']) && is_numeric($_POST['category_id'])) {
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to edit stock.';
    } else {
        $editId = intval($_POST['category_id']);
        $category_name = trim($_POST['category_name'] ?? '');
        $purchase_price = floatval($_POST['purchase_price'] ?? 0);
        $gram_value = floatval($_POST['gram_value'] ?? 0);
        $min_stock_level = floatval($_POST['min_stock_level'] ?? 0);
        
        if (empty($category_name)) {
            $error = "Category name is required.";
        } elseif ($purchase_price <= 0) {
            $error = "Purchase price must be greater than 0.";
        } else {
            $stmt = $conn->prepare("UPDATE category SET category_name = ?, purchase_price = ?, gram_value = ?, min_stock_level = ? WHERE id = ?");
            $stmt->bind_param("sdddi", $category_name, $purchase_price, $gram_value, $min_stock_level, $editId);
            
            if ($stmt->execute()) {
                $log_desc = "Updated stock category: " . $category_name;
                $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'update', ?)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                $log_stmt->execute();
                $log_stmt->close();
                
                $success = "Stock category updated successfully.";
            } else {
                $error = "Failed to update stock category.";
            }
            $stmt->close();
        }
    }
}

// Handle edit product stock item - Admin only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_product_stock' && isset($_POST['product_id']) && is_numeric($_POST['product_id'])) {
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to edit stock.';
    } else {
        $editId = intval($_POST['product_id']);
        $product_name = trim($_POST['product_name'] ?? '');
        $product_type = trim($_POST['product_type'] ?? 'direct');
        $primary_unit = trim($_POST['primary_unit'] ?? '');
        $min_stock_level = floatval($_POST['min_stock_level'] ?? 0);
        
        if (empty($product_name)) {
            $error = "Product name is required.";
        } elseif (empty($primary_unit)) {
            $error = "Primary unit is required.";
        } else {
            $stmt = $conn->prepare("UPDATE product SET product_name = ?, product_type = ?, primary_unit = ?, min_stock_level = ? WHERE id = ?");
            $stmt->bind_param("sssdi", $product_name, $product_type, $primary_unit, $min_stock_level, $editId);
            
            if ($stmt->execute()) {
                $log_desc = "Updated product stock: " . $product_name;
                $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'update', ?)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                $log_stmt->execute();
                $log_stmt->close();
                
                $success = "Product stock updated successfully.";
            } else {
                $error = "Failed to update product stock.";
            }
            $stmt->close();
        }
    }
}

// Handle delete category stock item - Admin only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_category_stock' && isset($_POST['category_id']) && is_numeric($_POST['category_id'])) {
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to delete stock items.';
    } else {
        $deleteId = intval($_POST['category_id']);
        
        $check_stock = $conn->prepare("SELECT total_quantity, category_name FROM category WHERE id = ?");
        $check_stock->bind_param("i", $deleteId);
        $check_stock->execute();
        $result = $check_stock->get_result();
        $category = $result->fetch_assoc();
        
        if ($category['total_quantity'] > 0) {
            $error = "Cannot delete. Category still has stock: " . number_format($category['total_quantity'], 2) . " PCS";
        } else {
            $check_purchases = $conn->prepare("SELECT id FROM purchase_item WHERE cat_id = ? LIMIT 1");
            $check_purchases->bind_param("i", $deleteId);
            $check_purchases->execute();
            $check_purchases->store_result();
            
            if ($check_purchases->num_rows > 0) {
                $error = "Cannot delete. Category has purchase history.";
            } else {
                $stmt = $conn->prepare("DELETE FROM category WHERE id = ?");
                $stmt->bind_param("i", $deleteId);
                
                if ($stmt->execute()) {
                    $log_desc = "Deleted stock category: " . $category['category_name'];
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'delete', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                    $log_stmt->close();
                    
                    $success = "Stock category deleted successfully.";
                } else {
                    $error = "Failed to delete stock category.";
                }
                $stmt->close();
            }
            $check_purchases->close();
        }
        $check_stock->close();
    }
}

// Handle delete product stock item - Admin only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_product_stock' && isset($_POST['product_id']) && is_numeric($_POST['product_id'])) {
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to delete stock items.';
    } else {
        $deleteId = intval($_POST['product_id']);
        
        $check_stock = $conn->prepare("SELECT stock_quantity, product_name FROM product WHERE id = ?");
        $check_stock->bind_param("i", $deleteId);
        $check_stock->execute();
        $result = $check_stock->get_result();
        $product = $result->fetch_assoc();
        
        if ($product['stock_quantity'] > 0) {
            $error = "Cannot delete. Product still has stock: " . number_format($product['stock_quantity'], 2) . " " . strtoupper($product['primary_unit'] ?? 'pcs');
        } else {
            $check_purchases = $conn->prepare("SELECT id FROM purchase_item WHERE product_id = ? LIMIT 1");
            $check_purchases->bind_param("i", $deleteId);
            $check_purchases->execute();
            $check_purchases->store_result();
            
            if ($check_purchases->num_rows > 0) {
                $error = "Cannot delete. Product has purchase history.";
            } else {
                $stmt = $conn->prepare("DELETE FROM product WHERE id = ?");
                $stmt->bind_param("i", $deleteId);
                
                if ($stmt->execute()) {
                    $log_desc = "Deleted product stock: " . $product['product_name'];
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'delete', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                    $log_stmt->close();
                    
                    $success = "Product stock deleted successfully.";
                } else {
                    $error = "Failed to delete product stock.";
                }
                $stmt->close();
            }
            $check_purchases->close();
        }
        $check_stock->close();
    }
}

// Filters
$filterStock = $_GET['filter_stock'] ?? '';
$filterType = $_GET['filter_type'] ?? 'all'; // all, category, product
$filterCategory = $_GET['filter_category'] ?? '';
$filterProduct = $_GET['filter_product'] ?? '';

// Get categories (preforms)
$cat_where = "1=1";
$cat_params = [];
$cat_types = "";

if ($filterStock === 'critical') {
    $cat_where .= " AND total_quantity <= (min_stock_level * 0.25) AND min_stock_level > 0";
} elseif ($filterStock === 'low') {
    $cat_where .= " AND total_quantity <= min_stock_level AND total_quantity > (min_stock_level * 0.25) AND min_stock_level > 0";
} elseif ($filterStock === 'normal') {
    $cat_where .= " AND total_quantity > min_stock_level AND min_stock_level > 0";
} elseif ($filterStock === 'overstock') {
    $cat_where .= " AND total_quantity > (min_stock_level * 2) AND min_stock_level > 0";
} elseif ($filterStock === 'zero') {
    $cat_where .= " AND total_quantity <= 0";
} elseif ($filterStock === 'no_min') {
    $cat_where .= " AND (min_stock_level IS NULL OR min_stock_level = 0)";
}

if ($filterCategory && $filterCategory !== 'all') {
    $cat_where .= " AND category_name LIKE ?";
    $cat_params[] = "%$filterCategory%";
    $cat_types .= "s";
}

$cat_sql = "SELECT *, 'category' as stock_type FROM category WHERE $cat_where ORDER BY 
        CASE 
            WHEN total_quantity <= min_stock_level AND min_stock_level > 0 THEN 1
            WHEN total_quantity <= 0 THEN 2
            ELSE 3
        END, 
        (total_quantity / NULLIF(min_stock_level, 0)) ASC";

if ($cat_params) {
    $cat_stmt = $conn->prepare($cat_sql);
    $cat_stmt->bind_param($cat_types, ...$cat_params);
    $cat_stmt->execute();
    $categories = $cat_stmt->get_result();
} else {
    $categories = $conn->query($cat_sql);
}

// Get products (finished goods)
$prod_where = "1=1";
$prod_params = [];
$prod_types = "";

if ($filterStock === 'critical') {
    $prod_where .= " AND stock_quantity <= (min_stock_level * 0.25) AND min_stock_level > 0";
} elseif ($filterStock === 'low') {
    $prod_where .= " AND stock_quantity <= min_stock_level AND stock_quantity > (min_stock_level * 0.25) AND min_stock_level > 0";
} elseif ($filterStock === 'normal') {
    $prod_where .= " AND stock_quantity > min_stock_level AND min_stock_level > 0";
} elseif ($filterStock === 'overstock') {
    $prod_where .= " AND stock_quantity > (min_stock_level * 2) AND min_stock_level > 0";
} elseif ($filterStock === 'zero') {
    $prod_where .= " AND stock_quantity <= 0";
} elseif ($filterStock === 'no_min') {
    $prod_where .= " AND (min_stock_level IS NULL OR min_stock_level = 0)";
}

if ($filterProduct && $filterProduct !== 'all') {
    $prod_where .= " AND product_name LIKE ?";
    $prod_params[] = "%$filterProduct%";
    $prod_types .= "s";
}

$prod_sql = "SELECT *, 'product' as stock_type, stock_quantity as total_quantity FROM product WHERE $prod_where ORDER BY 
        CASE 
            WHEN stock_quantity <= min_stock_level AND min_stock_level > 0 THEN 1
            WHEN stock_quantity <= 0 THEN 2
            ELSE 3
        END, 
        (stock_quantity / NULLIF(min_stock_level, 0)) ASC";

if ($prod_params) {
    $prod_stmt = $conn->prepare($prod_sql);
    $prod_stmt->bind_param($prod_types, ...$prod_params);
    $prod_stmt->execute();
    $products = $prod_stmt->get_result();
} else {
    $products = $conn->query($prod_sql);
}

// Combine results based on filter type
$all_items = [];
if ($filterType === 'all' || $filterType === 'category') {
    if ($categories && $categories->num_rows > 0) {
        while ($row = $categories->fetch_assoc()) {
            $all_items[] = $row;
        }
    }
}
if ($filterType === 'all' || $filterType === 'product') {
    if ($products && $products->num_rows > 0) {
        while ($row = $products->fetch_assoc()) {
            $all_items[] = $row;
        }
    }
}

// Sort combined items by stock status
usort($all_items, function($a, $b) {
    $a_min = $a['min_stock_level'] ?? 0;
    $b_min = $b['min_stock_level'] ?? 0;
    $a_qty = $a['total_quantity'] ?? 0;
    $b_qty = $b['total_quantity'] ?? 0;
    
    $a_ratio = $a_min > 0 ? $a_qty / $a_min : 999;
    $b_ratio = $b_min > 0 ? $b_qty / $b_min : 999;
    
    if ($a_qty <= 0) return -1;
    if ($b_qty <= 0) return 1;
    if ($a_min > 0 && $a_qty <= $a_min) return -1;
    if ($b_min > 0 && $b_qty <= $b_min) return 1;
    
    return $a_ratio <=> $b_ratio;
});

// Stats
$result = $conn->query("SELECT COUNT(*) as cnt FROM category");
$totalCategories = ($result && $row = $result->fetch_assoc()) ? $row['cnt'] : 0;

$result = $conn->query("SELECT COUNT(*) as cnt FROM product");
$totalProducts = ($result && $row = $result->fetch_assoc()) ? $row['cnt'] : 0;

$result = $conn->query("SELECT COALESCE(SUM(total_quantity), 0) as total FROM category");
$totalCategoryStock = ($result && $row = $result->fetch_assoc()) ? $row['total'] : 0;

$result = $conn->query("SELECT COALESCE(SUM(stock_quantity), 0) as total FROM product");
$totalProductStock = ($result && $row = $result->fetch_assoc()) ? $row['total'] : 0;

$result = $conn->query("SELECT COALESCE(SUM(purchase_price * total_quantity), 0) as total FROM category");
$totalCategoryValue = ($result && $row = $result->fetch_assoc()) ? $row['total'] : 0;

$result = $conn->query("SELECT COALESCE(SUM(purchase_price * stock_quantity), 0) as total FROM product");
$totalProductValue = ($result && $row = $result->fetch_assoc()) ? $row['total'] : 0;

// Stock level counts for categories
$result = $conn->query("SELECT COUNT(*) as cnt FROM category WHERE total_quantity <= (min_stock_level * 0.25) AND min_stock_level > 0");
$catCriticalCount = ($result && $row = $result->fetch_assoc()) ? $row['cnt'] : 0;

$result = $conn->query("SELECT COUNT(*) as cnt FROM category WHERE total_quantity <= min_stock_level AND total_quantity > (min_stock_level * 0.25) AND min_stock_level > 0");
$catLowCount = ($result && $row = $result->fetch_assoc()) ? $row['cnt'] : 0;

$result = $conn->query("SELECT COUNT(*) as cnt FROM category WHERE total_quantity > min_stock_level AND min_stock_level > 0");
$catNormalCount = ($result && $row = $result->fetch_assoc()) ? $row['cnt'] : 0;

$result = $conn->query("SELECT COUNT(*) as cnt FROM category WHERE total_quantity <= 0");
$catZeroCount = ($result && $row = $result->fetch_assoc()) ? $row['cnt'] : 0;

// Stock level counts for products
$result = $conn->query("SELECT COUNT(*) as cnt FROM product WHERE stock_quantity <= (min_stock_level * 0.25) AND min_stock_level > 0");
$prodCriticalCount = ($result && $row = $result->fetch_assoc()) ? $row['cnt'] : 0;

$result = $conn->query("SELECT COUNT(*) as cnt FROM product WHERE stock_quantity <= min_stock_level AND stock_quantity > (min_stock_level * 0.25) AND min_stock_level > 0");
$prodLowCount = ($result && $row = $result->fetch_assoc()) ? $row['cnt'] : 0;

$result = $conn->query("SELECT COUNT(*) as cnt FROM product WHERE stock_quantity > min_stock_level AND min_stock_level > 0");
$prodNormalCount = ($result && $row = $result->fetch_assoc()) ? $row['cnt'] : 0;

$result = $conn->query("SELECT COUNT(*) as cnt FROM product WHERE stock_quantity <= 0");
$prodZeroCount = ($result && $row = $result->fetch_assoc()) ? $row['cnt'] : 0;

// Top 5 categories by value
$topValueQuery = "SELECT category_name, (purchase_price * total_quantity) as stock_value 
                  FROM category 
                  WHERE total_quantity > 0 
                  ORDER BY stock_value DESC 
                  LIMIT 5";
$topValue = $conn->query($topValueQuery);

// Top 5 products by value
$topProductValueQuery = "SELECT product_name, primary_unit, (purchase_price * stock_quantity) as stock_value 
                         FROM product 
                         WHERE stock_quantity > 0 
                         ORDER BY stock_value DESC 
                         LIMIT 5";
$topProductValue = $conn->query($topProductValueQuery);

// Stock status helper
function getStockStatus($current, $min, $type = 'category') {
    if ($current <= 0) {
        return ['class' => 'cancelled', 'text' => 'Out of Stock', 'icon' => 'bi-x-circle'];
    } elseif ($min <= 0) {
        return ['class' => 'pending', 'text' => 'No Minimum', 'icon' => 'bi-exclamation-circle'];
    } elseif ($current <= ($min * 0.25)) {
        return ['class' => 'cancelled', 'text' => 'Critical', 'icon' => 'bi-exclamation-triangle'];
    } elseif ($current <= $min) {
        return ['class' => 'pending', 'text' => 'Low Stock', 'icon' => 'bi-exclamation-diamond'];
    } elseif ($current > ($min * 2)) {
        return ['class' => 'info', 'text' => 'Overstock', 'icon' => 'bi-box'];
    } else {
        return ['class' => 'completed', 'text' => 'Normal', 'icon' => 'bi-check-circle'];
    }
}

// Check if user is admin for action buttons
$is_admin = ($_SESSION['user_role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        .stock-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
        }
        .stock-indicator.critical { background: #dc2626; }
        .stock-indicator.low { background: #f59e0b; }
        .stock-indicator.normal { background: #10b981; }
        .stock-indicator.overstock { background: #6366f1; }
        
        .stock-bar-container {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 150px;
        }
        
        .stock-bar {
            flex: 1;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .stock-bar-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .stock-bar-fill.critical { background: #dc2626; }
        .stock-bar-fill.low { background: #f59e0b; }
        .stock-bar-fill.normal { background: #10b981; }
        .stock-bar-fill.overstock { background: #6366f1; }
        
        .value-highlight {
            font-weight: 600;
            color: #1e293b;
        }
        
        .value-muted {
            color: #64748b;
            font-size: 12px;
        }
        
        .stock-value-badge {
            background: #f1f5f9;
            padding: 4px 8px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .stock-value-badge i {
            color: #2463eb;
            margin-right: 4px;
        }
        
        .type-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .type-badge.category {
            background: #f0fdf4;
            color: #16a34a;
        }
        
        .type-badge.product {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .quick-stock-form {
            display: flex;
            gap: 5px;
            align-items: center;
        }
        
        .quick-stock-input {
            width: 80px;
            padding: 4px 8px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 12px;
        }
        
        .quick-stock-input:focus {
            outline: none;
            border-color: #2463eb;
        }
        
        .quick-stock-btn {
            padding: 4px 8px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .quick-stock-btn.add {
            background: #10b981;
            color: white;
        }
        
        .quick-stock-btn.add:hover {
            background: #059669;
        }
        
        .quick-stock-btn.subtract {
            background: #ef4444;
            color: white;
        }
        
        .quick-stock-btn.subtract:hover {
            background: #dc2626;
        }
        
        .permission-badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #f1f5f9;
            color: #64748b;
        }
        
        .filter-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .filter-badge.critical { background: #fee2e2; color: #dc2626; }
        .filter-badge.low { background: #fff3e0; color: #f59e0b; }
        .filter-badge.normal { background: #e0f2e7; color: #10b981; }
        .filter-badge.overstock { background: #e0e7ff; color: #6366f1; }
        .filter-badge.zero { background: #f1f5f9; color: #64748b; }
        
        /* Type Filter Buttons */
        .type-filter-btn {
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .type-filter-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        /* View Modal Styles */
        .info-grid-view {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .info-card-view {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid #e2e8f0;
        }
        
        .info-title {
            font-size: 14px;
            font-weight: 600;
            color: #2563eb;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 8px;
            font-size: 13px;
        }
        
        .info-label {
            width: 120px;
            color: #64748b;
            font-weight: 500;
        }
        
        .info-value {
            flex: 1;
            color: #1e293b;
            font-weight: 500;
        }
        
        .purchase-item {
            background: white;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 8px;
            border: 1px solid #e2e8f0;
        }
        
        .purchase-item:hover {
            background: #f8fafc;
        }
        
        .purchase-number {
            font-weight: 600;
            color: #2563eb;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: center;
        }
        
        .action-btn {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            border: 1px solid #e2e8f0;
            background: white;
            color: #475569;
            transition: all 0.2s;
        }
        
        .action-btn:hover {
            background: #f8fafc;
            transform: translateY(-1px);
        }
        
        .action-btn.view:hover {
            border-color: #2563eb;
            color: #2563eb;
        }
        
        .action-btn.edit:hover {
            border-color: #10b981;
            color: #10b981;
        }
        
        .action-btn.delete:hover {
            border-color: #dc2626;
            color: #dc2626;
        }
    </style>
</head>
<body>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>

        <div class="page-content">

            <!-- Page Header -->
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Stock Levels</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Monitor and manage inventory stock levels for preforms and finished goods</p>
                </div>
                <?php if ($is_admin): ?>
                    <div class="d-flex gap-2">
                        <a href="categories.php" class="btn-outline-custom">
                            <i class="bi bi-tags"></i> Manage Categories
                        </a>
                        <a href="products.php" class="btn-outline-custom">
                            <i class="bi bi-box-seam"></i> Manage Products
                        </a>
                    </div>
                <?php else: ?>
                    <span class="permission-badge"><i class="bi bi-eye"></i> View Only Mode</span>
                <?php endif; ?>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert" data-testid="alert-success">
                    <i class="bi bi-check-circle-fill"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2" role="alert" data-testid="alert-error">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-total-items">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon blue">
                                <i class="bi bi-boxes"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Total Items</div>
                                <div class="stat-value" data-testid="stat-value-items"><?php echo $totalCategories + $totalProducts; ?></div>
                                <div class="stat-sub"><?php echo $totalCategories; ?> Categories | <?php echo $totalProducts; ?> Products</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-total-stock">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon green">
                                <i class="bi bi-cubes"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Total Stock</div>
                                <div class="stat-value" data-testid="stat-value-stock"><?php echo number_format($totalCategoryStock + $totalProductStock, 2); ?></div>
                                <div class="stat-sub"><?php echo number_format($totalCategoryStock, 2); ?> PCS (Cat) | <?php echo number_format($totalProductStock, 2); ?> (Prod)</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-total-value">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon purple">
                                <i class="bi bi-currency-rupee"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Stock Value</div>
                                <div class="stat-value" data-testid="stat-value-value">₹<?php echo number_format($totalCategoryValue + $totalProductValue, 2); ?></div>
                                <div class="stat-sub">Cat: ₹<?php echo number_format($totalCategoryValue, 2); ?> | Prod: ₹<?php echo number_format($totalProductValue, 2); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-alerts">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon orange">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Stock Alerts</div>
                                <div class="stat-value" data-testid="stat-value-alerts"><?php echo $catCriticalCount + $catLowCount + $prodCriticalCount + $prodLowCount; ?></div>
                                <div class="stat-sub">Cat: <?php echo $catCriticalCount + $catLowCount; ?> | Prod: <?php echo $prodCriticalCount + $prodLowCount; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stock Status Summary -->
            <div class="row g-3 mb-4">
                <div class="col-md-12">
                    <div class="dashboard-card">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <h6 class="fw-semibold mb-2"><span class="type-badge category">Categories (Preforms)</span></h6>
                                    <div class="row g-2 text-center">
                                        <div class="col">
                                            <div class="filter-badge critical mb-1"><i class="bi bi-exclamation-triangle me-1"></i> <?php echo $catCriticalCount; ?></div>
                                            <div class="value-muted">Critical</div>
                                        </div>
                                        <div class="col">
                                            <div class="filter-badge low mb-1"><i class="bi bi-exclamation-diamond me-1"></i> <?php echo $catLowCount; ?></div>
                                            <div class="value-muted">Low</div>
                                        </div>
                                        <div class="col">
                                            <div class="filter-badge normal mb-1"><i class="bi bi-check-circle me-1"></i> <?php echo $catNormalCount; ?></div>
                                            <div class="value-muted">Normal</div>
                                        </div>
                                        <div class="col">
                                            <div class="filter-badge zero mb-1"><i class="bi bi-x-circle me-1"></i> <?php echo $catZeroCount; ?></div>
                                            <div class="value-muted">Zero</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="fw-semibold mb-2"><span class="type-badge product">Products (Finished Goods)</span></h6>
                                    <div class="row g-2 text-center">
                                        <div class="col">
                                            <div class="filter-badge critical mb-1"><i class="bi bi-exclamation-triangle me-1"></i> <?php echo $prodCriticalCount; ?></div>
                                            <div class="value-muted">Critical</div>
                                        </div>
                                        <div class="col">
                                            <div class="filter-badge low mb-1"><i class="bi bi-exclamation-diamond me-1"></i> <?php echo $prodLowCount; ?></div>
                                            <div class="value-muted">Low</div>
                                        </div>
                                        <div class="col">
                                            <div class="filter-badge normal mb-1"><i class="bi bi-check-circle me-1"></i> <?php echo $prodNormalCount; ?></div>
                                            <div class="value-muted">Normal</div>
                                        </div>
                                        <div class="col">
                                            <div class="filter-badge zero mb-1"><i class="bi bi-x-circle me-1"></i> <?php echo $prodZeroCount; ?></div>
                                            <div class="value-muted">Zero</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top Values Row -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="dashboard-card">
                        <div class="card-body">
                            <h6 class="fw-semibold mb-2">Top Categories by Value</h6>
                            <?php if ($topValue && $topValue->num_rows > 0): ?>
                                <?php while ($item = $topValue->fetch_assoc()): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="value-muted"><?php echo htmlspecialchars($item['category_name']); ?></span>
                                        <span class="fw-semibold">₹<?php echo number_format($item['stock_value'], 2); ?></span>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="value-muted text-center">No category stock data available</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="dashboard-card">
                        <div class="card-body">
                            <h6 class="fw-semibold mb-2">Top Products by Value</h6>
                            <?php if ($topProductValue && $topProductValue->num_rows > 0): ?>
                                <?php while ($item = $topProductValue->fetch_assoc()): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="value-muted"><?php echo htmlspecialchars($item['product_name']); ?> (<?php echo $item['primary_unit']; ?>)</span>
                                        <span class="fw-semibold">₹<?php echo number_format($item['stock_value'], 2); ?></span>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="value-muted text-center">No product stock data available</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Type Filter Buttons -->
            <div class="dashboard-card mb-4">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <span class="text-muted">Show:</span>
                        <a href="stocks.php?filter_type=all<?php echo $filterStock ? '&filter_stock='.$filterStock : ''; ?>" 
                           class="btn btn-sm type-filter-btn <?php echo $filterType === 'all' ? 'active' : 'btn-outline-secondary'; ?>">
                            <i class="bi bi-grid-3x3-gap-fill me-1"></i> All Items
                        </a>
                        <a href="stocks.php?filter_type=category<?php echo $filterStock ? '&filter_stock='.$filterStock : ''; ?>" 
                           class="btn btn-sm type-filter-btn <?php echo $filterType === 'category' ? 'active' : 'btn-outline-secondary'; ?>">
                            <i class="bi bi-layers me-1"></i> Categories (Preforms)
                        </a>
                        <a href="stocks.php?filter_type=product<?php echo $filterStock ? '&filter_stock='.$filterStock : ''; ?>" 
                           class="btn btn-sm type-filter-btn <?php echo $filterType === 'product' ? 'active' : 'btn-outline-secondary'; ?>">
                            <i class="bi bi-box-seam me-1"></i> Products (Finished Goods)
                        </a>
                    </div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="dashboard-card mb-4">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center gap-3 flex-wrap filter-bar-inner">
                        <div class="d-flex gap-1 flex-wrap filter-tabs">
                            <a href="stocks.php?filter_type=<?php echo $filterType; ?>" class="btn btn-sm <?php echo !$filterStock ? 'btn-primary' : 'btn-outline-secondary'; ?>" data-testid="filter-all">
                                All <span class="badge bg-white text-dark ms-1"><?php echo count($all_items); ?></span>
                            </a>
                            <a href="stocks.php?filter_stock=critical&filter_type=<?php echo $filterType; ?>" class="btn btn-sm <?php echo $filterStock === 'critical' ? 'btn-danger' : 'btn-outline-secondary'; ?>" data-testid="filter-critical">
                                Critical <span class="badge bg-white text-dark ms-1"><?php echo (($filterType === 'category' || $filterType === 'all') ? $catCriticalCount : 0) + (($filterType === 'product' || $filterType === 'all') ? $prodCriticalCount : 0); ?></span>
                            </a>
                            <a href="stocks.php?filter_stock=low&filter_type=<?php echo $filterType; ?>" class="btn btn-sm <?php echo $filterStock === 'low' ? 'btn-warning' : 'btn-outline-secondary'; ?>" data-testid="filter-low">
                                Low <span class="badge bg-white text-dark ms-1"><?php echo (($filterType === 'category' || $filterType === 'all') ? $catLowCount : 0) + (($filterType === 'product' || $filterType === 'all') ? $prodLowCount : 0); ?></span>
                            </a>
                            <a href="stocks.php?filter_stock=normal&filter_type=<?php echo $filterType; ?>" class="btn btn-sm <?php echo $filterStock === 'normal' ? 'btn-success' : 'btn-outline-secondary'; ?>" data-testid="filter-normal">
                                Normal <span class="badge bg-white text-dark ms-1"><?php echo (($filterType === 'category' || $filterType === 'all') ? $catNormalCount : 0) + (($filterType === 'product' || $filterType === 'all') ? $prodNormalCount : 0); ?></span>
                            </a>
                            <a href="stocks.php?filter_stock=zero&filter_type=<?php echo $filterType; ?>" class="btn btn-sm <?php echo $filterStock === 'zero' ? 'btn-secondary' : 'btn-outline-secondary'; ?>" data-testid="filter-zero">
                                Zero Stock <span class="badge bg-white text-dark ms-1"><?php echo (($filterType === 'category' || $filterType === 'all') ? $catZeroCount : 0) + (($filterType === 'product' || $filterType === 'all') ? $prodZeroCount : 0); ?></span>
                            </a>
                        </div>
                        <div class="ms-auto">
                            <a href="stocks.php?filter_type=<?php echo $filterType; ?>" class="btn btn-sm btn-outline-secondary" data-testid="clear-filters">
                                <i class="bi bi-x-circle"></i> Clear Filters
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stock Table -->
            <div class="dashboard-card" data-testid="stock-table">
                <div class="desktop-table" style="overflow-x: auto;">
                    <table class="table-custom" id="stockTable">
                        <thead>
                             <tr>
                                <th>#</th>
                                <th>Type</th>
                                <th>Item Name</th>
                                <th>Unit/Details</th>
                                <th>Purchase Price</th>
                                <th>Current Stock</th>
                                <th>Min Stock Level</th>
                                <th>Stock Status</th>
                                <th>Stock Value</th>
                                <?php if ($is_admin): ?>
                                    <th style="text-align: center;">Quick Update</th>
                                    <th style="text-align: center;">Actions</th>
                                <?php endif; ?>
                             </tr>
                        </thead>
                        <tbody>
                            <?php if (count($all_items) > 0): ?>
                                <?php foreach ($all_items as $index => $item): 
                                    $is_category = ($item['stock_type'] === 'category');
                                    $item_name = $is_category ? $item['category_name'] : $item['product_name'];
                                    $current_stock = $item['total_quantity'] ?? 0;
                                    $min_stock = $item['min_stock_level'] ?? 0;
                                    $purchase_price = $item['purchase_price'] ?? 0;
                                    $stock_status = getStockStatus($current_stock, $min_stock, $is_category ? 'category' : 'product');
                                    $stock_percentage = $min_stock > 0 ? min(($current_stock / $min_stock) * 100, 200) : 100;
                                    $bar_class = $stock_status['class'] === 'cancelled' ? 'critical' : 
                                                ($stock_status['class'] === 'pending' ? 'low' : 
                                                ($stock_status['class'] === 'info' ? 'overstock' : 'normal'));
                                    $type_badge_class = $is_category ? 'category' : 'product';
                                    $type_icon = $is_category ? 'bi-layers' : 'bi-box-seam';
                                    $type_label = $is_category ? 'Category' : 'Product';
                                    
                                    if ($is_category) {
                                        $details = $item['gram_value'] > 0 ? $item['gram_value'] . ' g/pc' : 'Preform';
                                        $unit = 'PCS';
                                    } else {
                                        $details = $item['product_type'] === 'direct' ? 'Direct Sale' : 'Converted Sale';
                                        $unit = strtoupper($item['primary_unit'] ?? 'PCS');
                                    }
                                ?>
                                    <tr data-testid="row-stock-<?php echo $item['id']; ?>" data-stock-id="<?php echo $item['id']; ?>" data-stock-type="<?php echo $is_category ? 'category' : 'product'; ?>">
                                        <td><span class="order-id">#<?php echo $item['id']; ?></span></td>
                                        <td>
                                            <span class="type-badge <?php echo $type_badge_class; ?>">
                                                <i class="bi <?php echo $type_icon; ?> me-1"></i>
                                                <?php echo $type_label; ?>
                                            </span>
                                        </td>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($item_name); ?></td>
                                        <td class="value-muted"><?php echo htmlspecialchars($details); ?></td>
                                        <td class="value-highlight">₹<?php echo number_format($purchase_price, 2); ?> / <?php echo $unit; ?></td>
                                        <td>
                                            <div class="stock-bar-container">
                                                <span class="fw-semibold stock-quantity"><?php echo number_format($current_stock, 2); ?> <?php echo $unit; ?></span>
                                                <div class="stock-bar" title="<?php echo $stock_percentage; ?>% of minimum">
                                                    <div class="stock-bar-fill <?php echo $bar_class; ?>" style="width: <?php echo min($stock_percentage, 100); ?>%;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($min_stock > 0): ?>
                                                <span class="value-muted"><?php echo number_format($min_stock, 2); ?> <?php echo $unit; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">Not set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $stock_status['class']; ?>">
                                                <i class="bi <?php echo $stock_status['icon']; ?> me-1"></i>
                                                <?php echo $stock_status['text']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="stock-value-badge">
                                                <i class="bi bi-currency-rupee"></i>
                                                <?php echo number_format($purchase_price * $current_stock, 2); ?>
                                            </span>
                                        </td>
                                        
                                        <?php if ($is_admin): ?>
                                            <td>
                                                <div class="quick-stock-form">
                                                    <form method="POST" action="stocks.php<?php echo $filterStock ? '?filter_stock='.$filterStock.'&filter_type='.$filterType : '?filter_type='.$filterType; ?>" style="display: flex; gap: 5px;" onsubmit="return validateStockForm(this)">
                                                        <input type="hidden" name="action" value="<?php echo $is_category ? 'update_category_stock' : 'update_product_stock'; ?>">
                                                        <input type="hidden" name="<?php echo $is_category ? 'category_id' : 'product_id'; ?>" value="<?php echo $item['id']; ?>">
                                                        <input type="hidden" name="operation" value="add">
                                                        <input type="number" name="stock_change" class="quick-stock-input" placeholder="Qty" step="0.001" min="0.001" required>
                                                        <button type="submit" class="quick-stock-btn add" title="Add Stock">
                                                            <i class="bi bi-plus"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" action="stocks.php<?php echo $filterStock ? '?filter_stock='.$filterStock.'&filter_type='.$filterType : '?filter_type='.$filterType; ?>" style="display: flex; gap: 5px;" onsubmit="return validateStockForm(this)">
                                                        <input type="hidden" name="action" value="<?php echo $is_category ? 'update_category_stock' : 'update_product_stock'; ?>">
                                                        <input type="hidden" name="<?php echo $is_category ? 'category_id' : 'product_id'; ?>" value="<?php echo $item['id']; ?>">
                                                        <input type="hidden" name="operation" value="subtract">
                                                        <input type="number" name="stock_change" class="quick-stock-input" placeholder="Qty" step="0.001" min="0.001" required>
                                                        <button type="submit" class="quick-stock-btn subtract" title="Remove Stock">
                                                            <i class="bi bi-dash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="action-btn view" onclick="viewStockItem(<?php echo $item['id']; ?>, '<?php echo $is_category ? 'category' : 'product'; ?>')" title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    
                                                    <button class="action-btn edit" data-bs-toggle="modal" data-bs-target="#editStockModal<?php echo $item['id']; ?>" title="Edit Item">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    
                                                    <?php if ($current_stock <= 0): ?>
                                                        <form method="POST" action="stocks.php<?php echo $filterStock ? '?filter_stock='.$filterStock.'&filter_type='.$filterType : '?filter_type='.$filterType; ?>" style="display: inline;" 
                                                              onsubmit="return confirm('Are you sure you want to delete this stock item? This action cannot be undone.')">
                                                            <input type="hidden" name="action" value="<?php echo $is_category ? 'delete_category_stock' : 'delete_product_stock'; ?>">
                                                            <input type="hidden" name="<?php echo $is_category ? 'category_id' : 'product_id'; ?>" value="<?php echo $item['id']; ?>">
                                                            <button type="submit" class="action-btn delete" title="Delete Item">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>

                                    <!-- Edit Stock Modal -->
                                    <div class="modal fade" id="editStockModal<?php echo $item['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST" action="stocks.php<?php echo $filterStock ? '?filter_stock='.$filterStock.'&filter_type='.$filterType : '?filter_type='.$filterType; ?>">
                                                    <input type="hidden" name="action" value="<?php echo $is_category ? 'edit_category_stock' : 'edit_product_stock'; ?>">
                                                    <input type="hidden" name="<?php echo $is_category ? 'category_id' : 'product_id'; ?>" value="<?php echo $item['id']; ?>">
                                                    
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">
                                                            <i class="bi bi-pencil-square me-2"></i>
                                                            Edit <?php echo $type_label; ?> Stock
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label"><?php echo $is_category ? 'Category Name' : 'Product Name'; ?> <span class="text-danger">*</span></label>
                                                            <input type="text" name="<?php echo $is_category ? 'category_name' : 'product_name'; ?>" class="form-control" 
                                                                   value="<?php echo htmlspecialchars($item_name); ?>" required>
                                                        </div>
                                                        
                                                        <?php if ($is_category): ?>
                                                            <div class="mb-3">
                                                                <label class="form-label">Gram Value (g/pc)</label>
                                                                <input type="number" name="gram_value" class="form-control" 
                                                                       step="0.001" min="0" value="<?php echo $item['gram_value']; ?>">
                                                                <small class="text-muted">Weight per piece in grams</small>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Purchase Price (₹) <span class="text-danger">*</span></label>
                                                                <div class="input-group">
                                                                    <span class="input-group-text">₹</span>
                                                                    <input type="number" name="purchase_price" class="form-control" 
                                                                           step="0.01" min="0.01" value="<?php echo $item['purchase_price']; ?>" required>
                                                                </div>
                                                                <small class="text-muted">Cost per piece</small>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="mb-3">
                                                                <label class="form-label">Product Type</label>
                                                                <select name="product_type" class="form-select">
                                                                    <option value="direct" <?php echo ($item['product_type'] ?? 'direct') === 'direct' ? 'selected' : ''; ?>>Direct Sale</option>
                                                                    <option value="converted" <?php echo ($item['product_type'] ?? 'direct') === 'converted' ? 'selected' : ''; ?>>Converted Sale</option>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Primary Unit <span class="text-danger">*</span></label>
                                                                <input type="text" name="primary_unit" class="form-control" 
                                                                       value="<?php echo htmlspecialchars($item['primary_unit'] ?? 'pcs'); ?>" required>
                                                                <small class="text-muted">e.g., pcs, bag, box, bottle</small>
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label">Minimum Stock Level</label>
                                                            <input type="number" name="min_stock_level" class="form-control" 
                                                                   step="0.001" min="0" value="<?php echo $item['min_stock_level']; ?>">
                                                            <small class="text-muted">Alert when stock falls below this level</small>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label">Current Stock</label>
                                                            <input type="text" class="form-control" value="<?php echo number_format($current_stock, 2); ?> <?php echo $is_category ? 'PCS' : strtoupper($item['primary_unit'] ?? 'PCS'); ?>" readonly disabled>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-primary">Save Changes</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?php echo $is_admin ? '11' : '10'; ?>" class="text-center py-5">
                                        <i class="bi bi-boxes d-block mb-2" style="font-size: 48px; color: #cbd5e1;"></i>
                                        <div style="font-size: 15px; font-weight: 500; margin-bottom: 4px;">No stock items found</div>
                                        <div style="font-size: 13px; color: #64748b;">
                                            <?php if ($filterStock): ?>
                                                Try changing your filters or <a href="stocks.php?filter_type=<?php echo $filterType; ?>">view all stock</a>
                                            <?php elseif ($filterType === 'category'): ?>
                                                <a href="categories.php">Add categories</a> to start tracking preform stock
                                            <?php elseif ($filterType === 'product'): ?>
                                                <a href="products.php">Add products</a> to start tracking finished goods stock
                                            <?php else: ?>
                                                <a href="categories.php">Add categories</a> or <a href="products.php">products</a> to start tracking stock
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards" style="padding: 12px;">
                    <?php foreach ($all_items as $item): 
                        $is_category = ($item['stock_type'] === 'category');
                        $item_name = $is_category ? $item['category_name'] : $item['product_name'];
                        $current_stock = $item['total_quantity'] ?? 0;
                        $min_stock = $item['min_stock_level'] ?? 0;
                        $purchase_price = $item['purchase_price'] ?? 0;
                        $stock_status = getStockStatus($current_stock, $min_stock, $is_category ? 'category' : 'product');
                        $stock_percentage = $min_stock > 0 ? min(($current_stock / $min_stock) * 100, 200) : 100;
                        $type_badge_class = $is_category ? 'category' : 'product';
                        $type_icon = $is_category ? 'bi-layers' : 'bi-box-seam';
                        $type_label = $is_category ? 'Category' : 'Product';
                        
                        if ($is_category) {
                            $unit = 'PCS';
                            $details = $item['gram_value'] > 0 ? $item['gram_value'] . ' g/pc' : 'Preform';
                        } else {
                            $unit = strtoupper($item['primary_unit'] ?? 'PCS');
                            $details = $item['product_type'] === 'direct' ? 'Direct Sale' : 'Converted Sale';
                        }
                    ?>
                        <div class="mobile-card" data-testid="mobile-card-stock-<?php echo $item['id']; ?>">
                            <div class="mobile-card-header">
                                <div>
                                    <span class="order-id">#<?php echo $item['id']; ?></span>
                                    <span class="customer-name ms-2 fw-semibold"><?php echo htmlspecialchars($item_name); ?></span>
                                </div>
                                <div class="d-flex gap-1">
                                    <span class="type-badge <?php echo $type_badge_class; ?>">
                                        <i class="bi <?php echo $type_icon; ?> me-1"></i>
                                        <?php echo $type_label; ?>
                                    </span>
                                    <span class="status-badge <?php echo $stock_status['class']; ?>">
                                        <i class="bi <?php echo $stock_status['icon']; ?> me-1"></i>
                                        <?php echo $stock_status['text']; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="mobile-card-row">
                                <span class="mobile-card-label">Details</span>
                                <span class="mobile-card-value value-muted"><?php echo htmlspecialchars($details); ?></span>
                            </div>
                            
                            <div class="mobile-card-row">
                                <span class="mobile-card-label">Purchase Price</span>
                                <span class="mobile-card-value">₹<?php echo number_format($purchase_price, 2); ?> / <?php echo $unit; ?></span>
                            </div>
                            
                            <div class="mobile-card-row">
                                <span class="mobile-card-label">Current Stock</span>
                                <span class="mobile-card-value">
                                    <span class="fw-semibold"><?php echo number_format($current_stock, 2); ?> <?php echo $unit; ?></span>
                                    <div class="stock-bar mt-1" style="width: 100%;">
                                        <div class="stock-bar-fill <?php echo $stock_status['class'] === 'cancelled' ? 'critical' : ($stock_status['class'] === 'pending' ? 'low' : 'normal'); ?>" 
                                             style="width: <?php echo min($stock_percentage, 100); ?>%;"></div>
                                    </div>
                                </span>
                            </div>
                            
                            <div class="mobile-card-row">
                                <span class="mobile-card-label">Min Stock Level</span>
                                <span class="mobile-card-value">
                                    <?php if ($min_stock > 0): ?>
                                        <?php echo number_format($min_stock, 2); ?> <?php echo $unit; ?>
                                    <?php else: ?>
                                        <span class="text-muted">Not set</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            
                            <div class="mobile-card-row">
                                <span class="mobile-card-label">Stock Value</span>
                                <span class="mobile-card-value fw-semibold" style="color: var(--primary);">
                                    ₹<?php echo number_format($purchase_price * $current_stock, 2); ?>
                                </span>
                            </div>
                            
                            <?php if ($is_admin): ?>
                                <div class="mobile-card-actions flex-column">
                                    <div class="d-flex gap-2 mb-2 w-100">
                                        <form method="POST" action="stocks.php<?php echo $filterStock ? '?filter_stock='.$filterStock.'&filter_type='.$filterType : '?filter_type='.$filterType; ?>" style="flex: 1;" onsubmit="return validateStockForm(this)">
                                            <input type="hidden" name="action" value="<?php echo $is_category ? 'update_category_stock' : 'update_product_stock'; ?>">
                                            <input type="hidden" name="<?php echo $is_category ? 'category_id' : 'product_id'; ?>" value="<?php echo $item['id']; ?>">
                                            <input type="hidden" name="operation" value="add">
                                            <div class="input-group input-group-sm">
                                                <input type="number" name="stock_change" class="form-control" placeholder="Add qty" step="0.001" min="0.001" required>
                                                <button type="submit" class="btn btn-success" type="button">
                                                    <i class="bi bi-plus"></i> Add
                                                </button>
                                            </div>
                                        </form>
                                        <form method="POST" action="stocks.php<?php echo $filterStock ? '?filter_stock='.$filterStock.'&filter_type='.$filterType : '?filter_type='.$filterType; ?>" style="flex: 1;" onsubmit="return validateStockForm(this)">
                                            <input type="hidden" name="action" value="<?php echo $is_category ? 'update_category_stock' : 'update_product_stock'; ?>">
                                            <input type="hidden" name="<?php echo $is_category ? 'category_id' : 'product_id'; ?>" value="<?php echo $item['id']; ?>">
                                            <input type="hidden" name="operation" value="subtract">
                                            <div class="input-group input-group-sm">
                                                <input type="number" name="stock_change" class="form-control" placeholder="Remove qty" step="0.001" min="0.001" required>
                                                <button type="submit" class="btn btn-danger" type="button">
                                                    <i class="bi bi-dash"></i> Remove
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                    
                                    <div class="d-flex gap-2 w-100">
                                        <button class="btn btn-sm btn-outline-info flex-fill" onclick="viewStockItem(<?php echo $item['id']; ?>, '<?php echo $is_category ? 'category' : 'product'; ?>')">
                                            <i class="bi bi-eye me-1"></i>View
                                        </button>
                                        <button class="btn btn-sm btn-outline-primary flex-fill" data-bs-toggle="modal" data-bs-target="#editStockModal<?php echo $item['id']; ?>">
                                            <i class="bi bi-pencil me-1"></i>Edit
                                        </button>
                                        <?php if ($current_stock <= 0): ?>
                                            <form method="POST" action="stocks.php<?php echo $filterStock ? '?filter_stock='.$filterStock.'&filter_type='.$filterType : '?filter_type='.$filterType; ?>" style="flex: 1;" 
                                                  onsubmit="return confirm('Delete this stock item?')">
                                                <input type="hidden" name="action" value="<?php echo $is_category ? 'delete_category_stock' : 'delete_product_stock'; ?>">
                                                <input type="hidden" name="<?php echo $is_category ? 'category_id' : 'product_id'; ?>" value="<?php echo $item['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                                    <i class="bi bi-trash me-1"></i>Delete
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if (count($all_items) === 0): ?>
                        <div style="text-align: center; padding: 40px 16px; color: var(--text-muted);">
                            <i class="bi bi-boxes d-block mb-2" style="font-size: 36px;"></i>
                            <div style="font-size: 15px; font-weight: 500; margin-bottom: 4px;">No stock items found</div>
                            <div style="font-size: 13px;">
                                <?php if ($filterStock): ?>
                                    Try changing your filters or <a href="stocks.php?filter_type=<?php echo $filterType; ?>">view all stock</a>
                                <?php elseif ($filterType === 'category'): ?>
                                    <a href="categories.php">Add categories</a> to start tracking preform stock
                                <?php elseif ($filterType === 'product'): ?>
                                    <a href="products.php">Add products</a> to start tracking finished goods stock
                                <?php else: ?>
                                    <a href="categories.php">Add categories</a> or <a href="products.php">products</a> to start tracking stock
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- View Stock Modal -->
<div class="modal fade" id="viewStockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-box-seam me-2" style="color: #2563eb;"></i>
                    Stock Item Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="viewStockContent">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading stock details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-2"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script>
$(document).ready(function() {
    $('#stockTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search stock:",
            lengthMenu: "Show _MENU_ items",
            info: "Showing _START_ to _END_ of _TOTAL_ items",
            emptyTable: "No stock items available"
        },
        columnDefs: [
            <?php if ($is_admin): ?>
            { orderable: false, targets: [-1, -2] }
            <?php else: ?>
            { orderable: false, targets: [] }
            <?php endif; ?>
        ]
    });
});

// View stock item details
function viewStockItem(id, type) {
    console.log('Viewing stock item ID:', id, 'Type:', type);
    
    $('#viewStockModal').modal('show');
    $('#viewStockContent').html(`
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2 text-muted">Loading stock details...</p>
        </div>
    `);
    
    $.ajax({
        url: '<?php echo $_SERVER['PHP_SELF']; ?>?ajax=get_stock&id=' + id + '&type=' + type,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let item = response.item;
                let stockStatus = getStockStatusText(item.total_quantity || item.stock_quantity, item.min_stock_level);
                let isCategory = (type === 'category');
                let unit = isCategory ? 'PCS' : (item.primary_unit ? item.primary_unit.toUpperCase() : 'PCS');
                
                let purchasesHtml = '';
                if (response.purchases && response.purchases.length > 0) {
                    purchasesHtml = '<h6 class="fw-semibold mb-3" style="color: #2563eb;"><i class="bi bi-cart-check me-2"></i>Recent Purchase History</h6>';
                    response.purchases.forEach(pur => {
                        let purchaseNo = pur.purchase_no || 'PUR-' + new Date(pur.created_at).toISOString().slice(0,10).replace(/-/g,'') + '-' + id;
                        let quantity = pur.quantity;
                        let pricePerUnit = pur.purchase_price;
                        
                        purchasesHtml += `
                            <div class="purchase-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="purchase-number">${escapeHtml(purchaseNo)}</span>
                                    <span class="text-muted" style="font-size: 12px;">${new Date(pur.created_at).toLocaleDateString()}</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <span>Quantity: <strong>${parseFloat(quantity).toFixed(2)} ${unit}</strong></span>
                                    <span>Rate: ₹${parseFloat(pricePerUnit).toFixed(2)}</span>
                                </div>
                            </div>
                        `;
                    });
                } else {
                    purchasesHtml = '<p class="text-muted text-center py-3">No purchase history found.</p>';
                }
                
                let html = `
                    <div class="info-grid-view">
                        <div class="info-card-view">
                            <div class="info-title">
                                <i class="bi bi-info-circle me-2"></i>Basic Information
                            </div>
                            <div class="info-row">
                                <span class="info-label">${isCategory ? 'Category' : 'Product'}:</span>
                                <span class="info-value">${escapeHtml(isCategory ? item.category_name : item.product_name)}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Type:</span>
                                <span class="info-value">${isCategory ? 'Preform / Raw Material' : (item.product_type === 'direct' ? 'Direct Sale Product' : 'Converted Sale Product')}</span>
                            </div>`;
                            
                            if (isCategory && item.gram_value > 0) {
                                html += `<div class="info-row">
                                    <span class="info-label">Gram Value:</span>
                                    <span class="info-value">${item.gram_value} g/piece</span>
                                </div>`;
                            }
                            
                            if (!isCategory && item.primary_unit) {
                                html += `<div class="info-row">
                                    <span class="info-label">Primary Unit:</span>
                                    <span class="info-value">${escapeHtml(item.primary_unit)}</span>
                                </div>`;
                            }
                            
                            html += `<div class="info-row">
                                <span class="info-label">Purchase Price:</span>
                                <span class="info-value">₹${parseFloat(item.purchase_price).toFixed(2)} / ${unit}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Min Stock Level:</span>
                                <span class="info-value">${item.min_stock_level > 0 ? parseFloat(item.min_stock_level).toFixed(2) + ' ' + unit : 'Not set'}</span>
                            </div>
                        </div>
                        
                        <div class="info-card-view">
                            <div class="info-title">
                                <i class="bi bi-boxes me-2"></i>Stock Status
                            </div>
                            <div class="info-row">
                                <span class="info-label">Current Stock:</span>
                                <span class="info-value"><strong>${parseFloat(item.total_quantity || item.stock_quantity).toFixed(2)} ${unit}</strong></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Status:</span>
                                <span class="info-value">
                                    <span class="status-badge ${stockStatus.class}">
                                        <i class="bi ${stockStatus.icon} me-1"></i>
                                        ${stockStatus.text}
                                    </span>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Stock Value:</span>
                                <span class="info-value">₹${(item.purchase_price * (item.total_quantity || item.stock_quantity)).toFixed(2)}</span>
                            </div>
                        </div>
                        
                        <div class="info-card-view">
                            <div class="info-title">
                                <i class="bi bi-gear me-2"></i>System Information
                            </div>
                            <div class="info-row">
                                <span class="info-label">Created:</span>
                                <span class="info-value">${new Date(item.created_at).toLocaleString()}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Last Updated:</span>
                                <span class="info-value">${new Date(item.updated_at).toLocaleString()}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="info-card-view mt-3">
                        <div class="info-title">
                            <i class="bi bi-clock-history me-2"></i>Purchase History
                        </div>
                        ${purchasesHtml}
                    </div>
                `;
                $('#viewStockContent').html(html);
            } else {
                $('#viewStockContent').html(`
                    <div class="alert alert-danger m-3">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Failed to load stock details: ${response.message || 'Unknown error'}
                    </div>
                `);
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error);
            $('#viewStockContent').html(`
                <div class="alert alert-danger m-3">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    An error occurred while loading details. Please try again.
                    <br><small>Error: ${error}</small>
                </div>
            `);
        }
    });
}

// Helper function for stock status
function getStockStatusText(current, min) {
    if (current <= 0) {
        return { class: 'cancelled', text: 'Out of Stock', icon: 'bi-x-circle' };
    } else if (min <= 0) {
        return { class: 'pending', text: 'No Minimum', icon: 'bi-exclamation-circle' };
    } else if (current <= (min * 0.25)) {
        return { class: 'cancelled', text: 'Critical', icon: 'bi-exclamation-triangle' };
    } else if (current <= min) {
        return { class: 'pending', text: 'Low Stock', icon: 'bi-exclamation-diamond' };
    } else if (current > (min * 2)) {
        return { class: 'info', text: 'Overstock', icon: 'bi-box' };
    } else {
        return { class: 'completed', text: 'Normal', icon: 'bi-check-circle' };
    }
}

// Validate stock form
function validateStockForm(form) {
    const input = form.querySelector('input[name="stock_change"]');
    const value = parseFloat(input.value);
    
    if (isNaN(value) || value <= 0) {
        alert('Please enter a valid positive quantity.');
        return false;
    }
    
    const operation = form.querySelector('input[name="operation"]')?.value;
    if (operation === 'subtract') {
        const row = form.closest('tr');
        if (row) {
            const stockCell = row.querySelector('td:nth-child(6) .fw-semibold');
            if (stockCell) {
                const currentStock = parseFloat(stockCell.textContent);
                if (value > currentStock) {
                    alert('Cannot remove more than current stock quantity.');
                    return false;
                }
            }
        }
    }
    
    return true;
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
</body>
</html>