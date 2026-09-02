document.addEventListener("input", (event) => {
    if (!(event.target instanceof HTMLInputElement) || event.target.name !== "title") return;
    const slug = event.target.form?.querySelector('input[name="slug"]');
    if (!(slug instanceof HTMLInputElement) || slug.dataset.slugAutofill !== "true" || slug.dataset.edited === "true") return;
    slug.value = event.target.value.toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
});

document.addEventListener("input", (event) => {
    if (event.target instanceof HTMLInputElement && event.target.name === "slug" && event.target.dataset.slugAutofill === "true") {
        event.target.dataset.edited = "true";
    }
});
