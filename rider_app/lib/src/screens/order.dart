import 'dart:convert';

class Order {
  final int id;
  final String orderNumber;
  final String orderStatus;
  final String fulfillmentType;
  final double total;
  final String deliveryAddress;
  final String? deliveryPhone;
  final String paymentMethod;
  final String customerName;
  final String? customerPhone;
  final String? customerEmail;
  final DateTime? createdAt;
  final String itemsSummary;
  final List<OrderItem> items;

  Order({
    required this.id,
    required this.orderNumber,
    required this.orderStatus,
    required this.fulfillmentType,
    required this.total,
    required this.deliveryAddress,
    this.deliveryPhone,
    required this.paymentMethod,
    required this.customerName,
    this.customerPhone,
    this.customerEmail,
    this.createdAt,
    this.itemsSummary = '',
    this.items = const [],
  });

  factory Order.fromJson(Map<String, dynamic> json) {
    List<OrderItem> orderItems = [];
    if (json['items'] != null && json['items'] is List) {
      orderItems = (json['items'] as List)
          .map((itemJson) => OrderItem.fromJson(Map<String, dynamic>.from(itemJson)))
          .toList();
    }

// The rider_orders_api returns `address`; customer/rider_api returns
    // `delivery_address`. Support both.
    final rawAddress = json['delivery_address'] ?? json['address'] ?? 'No address provided';

    // The delivery phone may come back under `delivery_phone` (order details)
    // or `phone` (assignments list). Support both.
    final rawDeliveryPhone =
        (json['delivery_phone']?.toString() ?? json['phone']?.toString()) ?? '';

    // The backend may return order id under `order_id` (assignments list) or
    // `id` (order details). Support both.
    final rawId = int.tryParse(json['order_id']?.toString() ?? json['id']?.toString() ?? '') ?? 0;

    DateTime? createdAt;
    final rawDate = json['created_at']?.toString();
    if (rawDate != null && rawDate.isNotEmpty) {
      createdAt = DateTime.tryParse(rawDate);
    }

    return Order(
      id: rawId,
      orderNumber: json['order_number'] ?? 'N/A',
orderStatus: json['order_status'] ?? 'unknown',
      fulfillmentType: json['fulfillment_type'] ?? 'delivery',
      total: double.tryParse(json['total'].toString()) ?? 0.0,
      deliveryAddress: rawAddress,
      deliveryPhone: rawDeliveryPhone.isNotEmpty ? rawDeliveryPhone : null,
      paymentMethod: json['payment_method'] ?? 'cod',
      customerName: json['customer_name'] ?? 'Unknown Customer',
      customerPhone: json['customer_phone']?.toString(),
      customerEmail: json['customer_email']?.toString(),
      createdAt: createdAt,
      itemsSummary: json['items_summary']?.toString() ?? '',
      items: orderItems,
    );
  }
}

class OrderItem {
  final int id;
  final int orderId;
  final int productId;
  final String productName;
  final int quantity;
  final double unitPrice;
  final double totalPrice;

  OrderItem({
    required this.id,
    required this.orderId,
    required this.productId,
    required this.productName,
    required this.quantity,
    required this.unitPrice,
    required this.totalPrice,
  });

  factory OrderItem.fromJson(Map<String, dynamic> json) {
    return OrderItem(
      id: int.tryParse(json['id'].toString()) ?? 0,
      orderId: int.tryParse(json['order_id'].toString()) ?? 0,
      productId: int.tryParse(json['product_id'].toString()) ?? 0,
      productName: json['product_name'] ?? 'Unknown Product',
      quantity: int.tryParse(json['quantity'].toString()) ?? 0,
      unitPrice: double.tryParse(json['unit_price'].toString()) ?? 0.0,
      totalPrice: double.tryParse(json['total_price'].toString()) ?? 0.0,
    );
  }
}

/// Helper function to safely decode JSON that might be a string.
Map<String, dynamic> safeJsonDecode(String source) {
  try {
    // First, try to decode directly.
    final decoded = json.decode(source);
    if (decoded is Map<String, dynamic>) {
      return decoded;
    }
    // If it decodes to something else (like a string from double encoding), wrap it.
    return {'message': decoded.toString()};
  } catch (e) {
    // If decoding fails, return the raw string as the message.
    return {'message': source};
  }
}
