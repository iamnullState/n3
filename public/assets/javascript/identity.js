document.addEventListener("click", (event) => {
    const button = event.target.closest("[data-password-toggle]");

    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    const input = document.getElementById(button.dataset.passwordToggle ?? "");

    if (!(input instanceof HTMLInputElement)) {
        return;
    }

    const showing = input.type === "text";
    input.type = showing ? "password" : "text";
    button.setAttribute("aria-pressed", showing ? "false" : "true");
    button.textContent = showing ? "Show" : "Hide";
});
