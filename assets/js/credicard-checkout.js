jQuery(function ($) {
  const BTN_SELECTOR = "#pagar";
  const FORM_SELECTOR = "form.checkout";
  let requestInFlight = false;
  function validarCamposFacturacionWoo() {
    let incompleto = false;
    $('p.validate-required input, p.validate-required select').each(function () {
      const $el = $(this);
      const valor = $el.val().trim();
      if ($el.is(":visible") && !valor) {
        $el.addClass("woocommerce-invalid");
        incompleto = true;
      } else {
        $el.removeClass("woocommerce-invalid");
      }
    });
    return !incompleto;
  }

  function showLoader() {
    if (document.getElementById("cc-loader")) return;
    const overlay = document.createElement("div");
    overlay.id = "cc-loader";
    overlay.style.cssText =
      "position:fixed;inset:0;z-index:9999;background:rgba(255,255,255,.55);display:flex;align-items:center;justify-content:center;backdrop-filter:blur(2px)";
    overlay.innerHTML =
      '<div style="width:70px;height:70px;border:7px solid #0079ff;border-top:7px solid transparent;border-radius:50%;animation:ccspin 1s linear infinite"></div>';
    document.body.appendChild(overlay);
    if (!document.getElementById("cc-spin-style")) {
      const style = document.createElement("style");
      style.id = "cc-spin-style";
      style.innerHTML = "@keyframes ccspin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}";
      document.head.appendChild(style);
    }
  }

  function hideLoader() {
    const overlay = document.getElementById("cc-loader");
    if (overlay) overlay.remove();
  }

  function mostrarError(texto) {
    if (typeof Swal !== 'undefined') {
      Swal.fire({ icon: 'error', title: 'Error', text: texto });
    } else {
      const contenedor = $("ul.woocommerce-error");
      if (contenedor.length) {
        contenedor.html(`<li>${texto}</li>`);
      } else {
        $("<ul class='woocommerce-error'><li>" + texto + "</li></ul>").prependTo(FORM_SELECTOR);
      }
    }
  }

  function createPaymentRequest() {
    return new Promise((resolve, reject) => {
      $.ajax({
        url: credicard_params.ajax_url,
        method: "POST",
        data: {
          action: "credicard_create_payment",
          security: credicard_params.nonce_create,
        },
        success: function (resp, textStatus, xhr) {
          if (xhr.responseText.trim() === '-1') {
            mostrarError("Nonce inválido o expirado. Recarga la página e intenta de nuevo.");
            reject();
            return;
          }
          resolve(resp);
        },
        error: function (xhr) {
          if (xhr.status === 403 || xhr.responseText.trim() === '-1') {
            mostrarError("Acceso denegado. Tu sesión ha expirado o el nonce no es válido.");
          } else {
            mostrarError("Error de red al contactar Credicard.");
          }
          reject();
        }
      });
    });
  }

  function openCredicardPopup(order) {
    console.log("En la funcio openCredicardPopup:", order);
    const width = 600;
    const height = 800;
    const left = window.screenX + (window.outerWidth - width) / 2;
    const top = window.screenY + (window.outerHeight - height) / 2;

    const handle = window.open(
      order.paymentUrl,
      "CredicardPayment",
      `width=${width},height=${height},left=${left},top=${top},resizable,scrollbars`
    );

    if (!handle) {
      mostrarError("Popup bloqueado. Habilita las ventanas emergentes en tu navegador.");
      return;
    }

    window.addEventListener("message", function (event) {
        
        // 1. Validar origen (según documentación de Credicard)
        
        if (!event.origin.includes("https://comercios.credicard.com.ve")) return;

        // Acceder a los datos enviados
        const data = event.data;

        console.log("Mensaje recibido del popup:", data);
        
        // 2. Manejar los estados según la documentación
        
        if (data.status === "payment-success-cc") {
            handlePaymentSuccess(order);
        } else if (data.status === "payment-fail-cc") {
            mostrarError("Pago fallido. Por favor, intenta nuevamente.");
        } else if (data.status === "payment-cancelled-cc") {
            mostrarError("Pago cancelado. Puedes reintentar cuando lo desees.");
        } else {
            console.warn("Estado desconocido:", data.status);
        }
        
    }, false);
    
    // Función para manejar pago exitoso
    function handlePaymentSuccess(order) {
        // Añadir campo hidden al formulario si no existe
        if (!$("#credicard_payment_id").length) {
            $("<input>", {
                type: "hidden",
                id: "credicard_payment_id",
                name: "credicard_payment_id",
                value: order.payment_id,
            }).appendTo("FORM_SELECTOR"); //Ajusta el selector según tu HTML
        }
        console.log("Finalizando orden con:", {
            payment_id: order.payment_id,
            email: $('input[name="billing_email"]').val()
        });
        // Enviar datos al servidor
        $.ajax({
            url: credicard_params.ajax_url,
            method: "POST",
            dataType: "json",
            data: {
                action: "credicard_finalize_order",
                security: credicard_params.nonce_finalize,
                payment_id: order.payment_id,
                billing_data: {
                  billing_email: $('input[name="billing_email"]').val(),
                  billing_first_name: $('input[name="billing_first_name"]').val(),
                  billing_last_name: $('input[name="billing_last_name"]').val(),
                  billing_phone: $('input[name="billing_phone"]').val()
                },
                fecha_asistencia: typeof fechaAsistencia !== "undefined" ? fechaAsistencia : ""
            },
            success: function(resp) {
                if (resp.success && resp.data?.redirect_url) {
                window.location.href = resp.data.redirect_url;
                } else {
                mostrarError(resp.data?.message || "Error al procesar la orden.");
                }
            },
            error: function(xhr) {
                mostrarError(`Error de red: ${xhr.statusText}`);
            }
        });
    }
    // Limpieza al cerrar el popup
    const timer = setInterval(() => {
        if (handle.closed) {
        clearInterval(timer);
        console.log("Popup cerrado");
        }
    }, 10000);

  }
  function toggleWooPlaceOrderButton(active) {
    const $btn = $('#place_order');
    $btn.prop("disabled", !active);
    $btn.css({ cursor: active ? "pointer" : "not-allowed", opacity: active ? 1 : 0.5 });
  }

  function togglePayButton(enable) {
    const $btn = $(BTN_SELECTOR);
    $btn.prop("disabled", !enable);
    $btn.css({
      cursor: enable ? "pointer" : "not-allowed",
      background: enable ? "linear-gradient(135deg, #0079ff, #0051cc)" : "gray",
      color: enable ? "#fff" : "lightgray"
    });
  }

  togglePayButton(false);

  $(document).on("click", BTN_SELECTOR, function (e) {
    e.preventDefault();
    if (requestInFlight || $(BTN_SELECTOR).prop("disabled")) return;

    showLoader();
    const isValid = validarCamposFacturacionWoo();
    hideLoader();

    if (!isValid) {
      mostrarError("Por favor completa los campos de facturación obligatorios.");
      return;
    }

    requestInFlight = true;
    showLoader();
    createPaymentRequest()
      .then(function (resp) {
        if (resp.success && resp.data?.id && resp.data?.paymentUrl) {
          togglePayButton(true);
          openCredicardPopup({ payment_id: resp.data.id, paymentUrl: resp.data.paymentUrl });
        } else {
          togglePayButton(false);
          mostrarError("No se pudo generar la orden de pago. Recargue la página e intente nuevamente");
        }
      })
      .catch(function () {
        togglePayButton(false);
      })
      .finally(function () {
        hideLoader();
        requestInFlight = false;
      });
  });

  $(document).on("change", 'input[name="payment_method"]', function () {
    const selected = $(this).val();
    if (selected === "credicard") {
      toggleWooPlaceOrderButton(false);
      togglePayButton(true); // Habilitamos el botón al seleccionar credicard
    } else {
      togglePayButton(false);
      toggleWooPlaceOrderButton(true);
    }
  });
});
