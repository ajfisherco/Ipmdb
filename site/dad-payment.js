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
const thankYou = document.querySelector("#thankYou");
const thanksAmount = document.querySelector("#thanksAmount");

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

function setPaymentButtons(active) {
  [payCard, payEtransfer, payQR].forEach((button) => {
    button.disabled = !active;
    button.classList.toggle("is-active", active);
  });
}

function setAmount(amount) {
  selectedAmount = Number(amount);

  if (!selectedAmount || selectedAmount <= 0) {
    selectedAmount = 0;
    contributionAmount.textContent = "$0.00";
    daysCovered.textContent = "DAYS COVERED: 0";
    nextPaymentDate.textContent = "NEXT PAYMENT DATE: —";
    setPaymentButtons(false);
    return;
  }

  const days = Math.floor(selectedAmount);
  contributionAmount.textContent = formatCurrency(selectedAmount);
  daysCovered.textContent = `DAYS COVERED: ${days}`;
  nextPaymentDate.textContent = `NEXT PAYMENT DATE: ${calculateNextDate(days)}`;
  transferAmount.textContent = `Amount: ${formatCurrency(selectedAmount)}`;
  thanksAmount.textContent = `Contribution: ${formatCurrency(selectedAmount)}`;

  options.classList.add("is-hidden");
  summary.classList.remove("is-hidden");
  setPaymentButtons(true);
}

function resetAmount() {
  selectedAmount = 0;
  customAmount.value = "";
  options.classList.remove("is-hidden");
  summary.classList.add("is-hidden");
  transferInstructions.classList.add("is-hidden");
  thankYou.classList.add("is-hidden");
  setPaymentButtons(false);
}

options.addEventListener("click", (event) => {
  const button = event.target.closest("[data-amount]");
  if (!button) return;
  setAmount(button.dataset.amount);
});

customAmount.addEventListener("change", () => setAmount(customAmount.value));
customAmount.addEventListener("keydown", (event) => {
  if (event.key === "Enter") setAmount(customAmount.value);
});

resetContribution.addEventListener("click", resetAmount);

payCard.addEventListener("click", () => {
  if (!selectedAmount) return;
  const url = `${SQUARE_CARD_URL}?amount=${encodeURIComponent(selectedAmount)}`;
  window.open(url, "_blank", "noopener,noreferrer");
  thankYou.classList.remove("is-hidden");
});

payEtransfer.addEventListener("click", async () => {
  if (!selectedAmount) return;
  transferInstructions.classList.remove("is-hidden");

  const message = `DAD Contribution ${formatCurrency(selectedAmount)} to ${INTERAC_EMAIL}`;
  try {
    await navigator.clipboard.writeText(message);
  } catch (error) {
    console.warn("Clipboard copy unavailable", error);
  }
});

payQR.addEventListener("click", () => {
  if (!selectedAmount) return;
  const url = `${SQUARE_QR_URL}?amount=${encodeURIComponent(selectedAmount)}`;
  window.open(url, "_blank", "noopener,noreferrer");
  thankYou.classList.remove("is-hidden");
});

setPaymentButtons(false);
