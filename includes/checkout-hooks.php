<?php
// Inyectar fecha de asistencia como variable JS en el checkout
add_action('woocommerce_checkout_after_order_review', function () {
   $cart = WC()->cart->get_cart();
   $fecha = '';

   foreach ($cart as $item) {
      if (isset($item['fecha_asistencia'])) {
         $fecha = $item['fecha_asistencia'];
         break;
      }
   }

   echo "<script>const fechaAsistencia = " . json_encode($fecha) . ";</script>";
});
