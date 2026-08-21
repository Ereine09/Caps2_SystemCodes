class Order {
  const Order({
    required this.id,
    required this.orderNumber,
    required this.status,
    this.customerName,
    this.customerEmail,
  });

  final int id;
  final String orderNumber;
  final String status;
  final String? customerName;
  final String? customerEmail;

  static const allowedStatuses = [
    'pending',
    'confirmed',
    'processing',
    'ready_for_pickup',
    'to_ship',
    'to_receive',
    'out_for_delivery',
    'completed',
    'cancelled',
  ];

  factory Order.fromJson(Map<String, dynamic> json) {
    return Order(
      id: int.tryParse('${json['id']}') ?? 0,
      orderNumber: '${json['order_number'] ?? 'Unknown'}',
      status: '${json['order_status'] ?? 'pending'}',
      customerName: json['customer_name']?.toString(),
      customerEmail: json['customer_email']?.toString(),
    );
  }

  Order withStatus(String nextStatus) => Order(
        id: id,
        orderNumber: orderNumber,
        status: nextStatus,
        customerName: customerName,
        customerEmail: customerEmail,
      );
}