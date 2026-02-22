(function () {
	var config = window.dwPaymentFallbackData || null;
	if (!config) {
		return;
	}

	var staticFallbackUrl = config.staticFallbackUrl || "";
	var fallbackName = config.fallbackName || "";
	var ajaxUrl = config.ajaxUrl || "";
	var nonce = config.nonce || "";
	var loading = false;

	function appendRetryButton(noticeEl, url) {
		if (!noticeEl || !url || noticeEl.querySelector(".dw-fallback-msg")) {
			return;
		}

		var wrapper = document.createElement("p");
		wrapper.className = "dw-fallback-msg";

		var txt = document.createElement("span");
		txt.className = "dw-fallback-msg__text";
		txt.textContent = (config.retryPrefixText || "Pagamento falhou. Você pode tentar com:") + " " + fallbackName + " ";
		wrapper.appendChild(txt);

		var btn = document.createElement("a");
		btn.className = "button dw-fallback-retry-button";
		btn.href = url;
		btn.textContent = config.retryButtonText || "Tentar com outro meio";
		wrapper.appendChild(btn);

		noticeEl.appendChild(wrapper);
	}

	function fetchRetryUrlAndAppend(noticeEl) {
		if (loading || !ajaxUrl || !nonce) {
			return;
		}
		loading = true;

		var body = new URLSearchParams();
		body.append("action", "dw_fallback_get_retry_url");
		body.append("nonce", nonce);

		fetch(ajaxUrl, {
			method: "POST",
			credentials: "same-origin",
			headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
			body: body.toString()
		})
			.then(function (resp) { return resp.json(); })
			.then(function (data) {
				loading = false;
				if (data && data.success && data.data && data.data.url) {
					appendRetryButton(noticeEl, data.data.url);
				}
			})
			.catch(function () { loading = false; });
	}

	function getCheckoutErrorNotice() {
		return document.querySelector(
			".woocommerce-error, .woocommerce-notice--error, .woocommerce-NoticeGroup-checkout .woocommerce-error, " +
			".wfacp_error_message, .wfacp-notice.wfacp-notice-error, .fkcart-error, .fk-checkout-error"
		);
	}

	function checkNoticeAndInject() {
		var notice = getCheckoutErrorNotice();
		if (!notice || !notice.textContent) {
			return;
		}
		if (staticFallbackUrl) {
			appendRetryButton(notice, staticFallbackUrl);
			return;
		}
		fetchRetryUrlAndAppend(notice);
	}

	var interval = setInterval(checkNoticeAndInject, 500);
	setTimeout(function () { clearInterval(interval); }, 15000);

	if (window.jQuery) {
		window.jQuery(document.body).on("updated_checkout checkout_error", checkNoticeAndInject);
	}

	if (window.MutationObserver) {
		var observer = new MutationObserver(function () { checkNoticeAndInject(); });
		observer.observe(document.body, { childList: true, subtree: true });
		setTimeout(function () { observer.disconnect(); }, 20000);
	}
})();
