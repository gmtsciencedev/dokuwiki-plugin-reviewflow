document.addEventListener("DOMContentLoaded", function () {
  console.log("reviewflow script loaded");

  const fingerprint = navigator.userAgent + navigator.language + screen.width + screen.height;

  crypto.subtle.digest("SHA-256", new TextEncoder().encode(fingerprint)).then(buf => {
    const hash = Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');

    document.querySelectorAll('form').forEach(form => {
      const rfStageInput = form.querySelector('input[name="reviewflow_stage"]');
      if (rfStageInput) {
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = "reviewflow_fp";
        input.value = hash;
        form.appendChild(input);
      }
    });
  });
});