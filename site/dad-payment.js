const SQUARE_CARD_URL = "PASTE_SQUARE_CARD_LINK_HERE";
const SQUARE_QR_URL = "PASTE_SQUARE_QR_LINK_HERE";
const INTERAC_EMAIL = "ajfisherco@gmail.com";

const options = document.querySelector("#dadOptions");
const summary = document.querySelector("#dadSummary");
const customAmount = document.querySelector("#dadAmount");
const contributionAmount = document.querySelector("#contributionAmount");
const daysCovered = document.querySelector("#daysCovered");
const nextPaymentDate = document.querySelector("#nextPaymentDate");
const resetContribution = document.querySelector("#resetContribution");
const payCard = document.querySelector("#payCard");
const payEtransfer = document.querySelector("#payEtransfer");
const payQR = document.querySelector("#payQR");
const transferInstructions = document.querySelector("#transferInstructions");
const transferAmount = document.querySelector("#transferAmount");
const launchNotice = document.querySelector("#launchNotice");
const launchNoticeText = document.querySelector("#launchNoticeText");

let selectedAmount = 0;

function formatCurrency(amount) {
  return amount.toLocaleString("en-CA", {
    style: "currency",
    currency: "CAD",
    minimumFractionDigits: 2
  });
}

function calculateNextDate(days) {
  const nextDate = new Date();
  nextDate.setDate(nextDate.getDate() + Math.max(days, 1));
  return nextDate.toLocaleDateString("en-CA", {
    month: "long",
    day: "numeric",
    year: "numeric"
  });
}

function hasLiveUrl(url) {
  return Boolean(url && !url.includes("PASTE_") && /^https?:\/\//i.test(url));
}

function buildPaymentUrl(baseUrl, amount) {
  const url = new URL(baseUrl);
  url.searchParams.set("amount", amount.toString());
  return url.toString();
}

function showNotice(message) {
  launchNoticeText.textContent = message;
  launchNotice.classList.remove("is-hidden");
}

function hideNotice() {
  launchNotice.classList.add("is-hidden");
  launchNoticeText.textContent = "";
}

function setPaymentButtons(active) {
  [payCard, payEtransfer, payQR].forEach((button) => {
    button.disabled = !active;
    button.classList.toggle("is-active", active);
  });
}

function setAmount(amount) {
  const nextAmount = Number(amount);

  if (!Number.isFinite(nextAmount) || nextAmount <= 0) {
    selectedAmount = 0;
    contributionAmount.textContent = "$0.00";
    daysCovered.textContent = "DAYS COVERED: 0";
    nextPaymentDate.textContent = "NEXT PAYMENT DATE: —";
    setPaymentButtons(false);
    return;
  }

  selectedAmount = Math.round(nextAmount * 100) / 100;
  const days = Math.floor(selectedAmount);

  contributionAmount.textContent = formatCurrency(selectedAmount);
  daysCovered.textContent = `DAYS COVERED: ${days}`;
  nextPaymentDate.textContent = `NEXT PAYMENT DATE: ${calculateNextDate(days)}`;
  transferAmount.textContent = `Amount: ${formatCurrency(selectedAmount)}`;

  options.classList.add("is-hidden");
  summary.classList.remove("is-hidden");
  transferInstructions.classList.add("is-hidden");
  hideNotice();
  setPaymentButtons(true);
}

function resetAmount() {
  selectedAmount = 0;
  customAmount.value = "";
  options.classList.remove("is-hidden");
  summary.classList.add("is-hidden");
  transferInstructions.classList.add("is-hidden");
  hideNotice();
  setPaymentButtons(false);
}

options.addEventListener("click", (event) => {
  const button = event.target.closest("[data-amount]");
  if (!button) return;
  setAmount(button.dataset.amount);
});

customAmount.addEventListener("input", () => {
  if (Number(customAmount.value) > 0) setAmount(customAmount.value);
});

customAmount.addEventListener("keydown", (event) => {
  if (event.key === "Enter") setAmount(customAmount.value);
});

resetContribution.addEventListener("click", resetAmount);

payCard.addEventListener("click", () => {
  if (!selectedAmount) return;

  if (!hasLiveUrl(SQUARE_CARD_URL)) {
    showNotice("Square card link required before launch.");
    return;
  }

  window.open(buildPaymentUrl(SQUARE_CARD_URL, selectedAmount), "_blank", "noopener,noreferrer");
});

payEtransfer.addEventListener("click", async () => {
  if (!selectedAmount) return;

  transferInstructions.classList.remove("is-hidden");
  showNotice("E-transfer instructions copied where supported.");

  const message = `DAD Contribution ${formatCurrency(selectedAmount)} to ${INTERAC_EMAIL}`;
  try {
    await navigator.clipboard.writeText(message);
  } catch (error) {
    console.warn("Clipboard copy unavailable", error);
  }
});

payQR.addEventListener("click", () => {
  if (!selectedAmount) return;

  if (!hasLiveUrl(SQUARE_QR_URL)) {
    showNotice("Square QR link required before launch.");
    return;
  }

  window.open(buildPaymentUrl(SQUARE_QR_URL, selectedAmount), "_blank", "noopener,noreferrer");
});

setPaymentButtons(false);
