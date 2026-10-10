function calcularSaldo() {
  const totalInput = document.getElementById("monto_total").value;
  const adelanto = parseFloat(document.getElementById("adelanto").value) || 0;

  const display = document.getElementById("saldo_display");
  const hidden = document.getElementById("saldo_hidden");
  const error = document.getElementById("saldo_error");
  const msg = document.getElementById("msg-ok");


  if (msg) {
    setTimeout(() => {
      msg.style.transition = "opacity 0.5s ease";
      msg.style.opacity = "0";
      setTimeout(() => {
        msg.style.display = "none";
      }, 500);
    }, 15000);
  }


  if (totalInput === "") {
    display.value = "Por definir";
    hidden.value = "";
    display.style.color = "#888";
    display.style.fontWeight = "600";
    error.style.display = "none";

    document.getElementById("adelanto").removeAttribute("max");
    return;
  }


  const total = parseFloat(totalInput);
  const saldo = total - adelanto;

  display.value = saldo.toFixed(2);
  hidden.value = saldo.toFixed(2);

  if (saldo < 0) {
    display.style.color = "#c00";
    display.style.fontWeight = "700";
    error.style.display = "block";
  } else {
    display.style.color = "#1a7a30";
    display.style.fontWeight = "700";
    error.style.display = "none";
  }

  document.getElementById("adelanto").max = total;
}

function actualizarItem(checkbox) {
  checkbox.checked
    ? checkbox.closest(".condicion-item").classList.add("seleccionada")
    : checkbox.closest(".condicion-item").classList.remove("seleccionada");
  actualizarContador();
}

function actualizarContador() {
  const checkboxes = document.querySelectorAll(
    '.condicion-item:not(.condicion-fija) input[type="checkbox"]',
  );
  const marcadas = [...checkboxes].filter((cb) => cb.checked).length;
  const c = document.getElementById("cond-count");
  if (c) c.textContent = marcadas;
  return marcadas;
}

function seleccionarTodas(marcar) {
  document
    .querySelectorAll(
      '.condicion-item:not(.condicion-fija) input[type="checkbox"]',
    )
    .forEach((cb) => {
      cb.checked = marcar;
      marcar
        ? cb.closest(".condicion-item").classList.add("seleccionada")
        : cb.closest(".condicion-item").classList.remove("seleccionada");
    });
  actualizarContador();
}

document
  .getElementById("form-contrato")
  .addEventListener("submit", function (e) {
    const totalInput = document.getElementById("monto_total").value;
    const saldoValue = document.getElementById("saldo_hidden").value;

    // 👉 SOLO validar si hay monto total
    if (totalInput !== "") {
      const saldo = parseFloat(saldoValue);

      if (isNaN(saldo) || saldo < 0) {
        e.preventDefault();
        alert("⚠ El adelanto no puede ser mayor al monto total.");
        document.getElementById("adelanto").focus();
        return;
      }
    }

    // condiciones (lo dejas igual)
    const hayCheckboxes = document.querySelectorAll(
      ".condicion-item:not(.condicion-fija)",
    ).length;

    if (hayCheckboxes > 0 && actualizarContador() === 0) {
      e.preventDefault();
      const aviso = document.getElementById("aviso-cond");
      if (aviso) aviso.style.display = "block";
      document.getElementById("lista-condiciones").scrollIntoView({
        behavior: "smooth",
      });
    }
  });

window.addEventListener("load", function () {
  calcularSaldo();
  actualizarContador();
});
