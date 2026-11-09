const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const formatRupee = (amount) => `₹${Number(amount).toLocaleString('en-IN')}`;
const sellPageClock = document.querySelector('#sell-page-time');

if (sellPageClock) {
    const timezone = sellPageClock.dataset.timezone;
    const formatter = new Intl.DateTimeFormat('en-GB', {
        timeZone: timezone,
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
    });

    const updateSellPageClock = () => {
        const now = new Date();
        sellPageClock.textContent = formatter.format(now);
        sellPageClock.dateTime = now.toISOString();
    };

    updateSellPageClock();
    setInterval(updateSellPageClock, 1000);
}

async function sendRequest(url, method, values = {}) {
    const body = new URLSearchParams(values);
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-TOKEN': csrfToken,
        },
        body: method === 'GET' ? undefined : body,
    });
    const payload = await response.json();

    if (!response.ok) {
        const validationMessage = payload.errors
            ? Object.values(payload.errors).flat()[0]
            : null;
        const error = new Error(validationMessage || payload.message || 'Something went wrong. Please try again.');
        error.addProductUrl = payload.add_product_url ?? null;
        throw error;
    }

    return payload;
}

const posApp = document.querySelector('#pos-app');

if (posApp) {
    const scanForm = document.querySelector('#scan-form');
    const barcodeInput = document.querySelector('#scan-barcode');
    const productSearchResults = document.querySelector('#product-search-results');
    const cartItems = document.querySelector('#cart-items');
    const emptyCart = document.querySelector('#empty-cart');
    const countLabel = document.querySelector('#item-count');
    const totalLabel = document.querySelector('#grand-total');
    const fulfillmentTypeInput = document.querySelector('#fulfillment-type');
    const paymentMethodInput = document.querySelector('#payment-method');
    const salesManagerInput = document.querySelector('#sales-manager');
    const customerSearchInput = document.querySelector('#customer-search');
    const customerSearchResults = document.querySelector('#customer-search-results');
    const selectedCustomerPanel = document.querySelector('#selected-customer');
    const selectedCustomerName = document.querySelector('#selected-customer-name');
    const selectedCustomerDetails = document.querySelector('#selected-customer-details');
    const clearCustomerButton = document.querySelector('#clear-customer');
    const newCustomerButton = document.querySelector('#show-new-customer');
    const newCustomerForm = document.querySelector('#new-customer-form');
    const customerMessage = document.querySelector('#customer-message');
    const creditHint = document.querySelector('#credit-hint');
    const deliveryHint = document.querySelector('#delivery-hint');
    const cashPaymentFields = document.querySelector('#cash-payment-fields');
    const cashInput = document.querySelector('#cash-received');
    const cashRequirementLabel = document.querySelector('#cash-received-requirement');
    const changeLabel = document.querySelector('#change-due');
    const checkoutButton = document.querySelector('#checkout-button');
    const printReceiptButton = document.querySelector('#print-receipt-button');
    const message = document.querySelector('#pos-message');
    let cart = window.initialPosCart ?? { items: [], total: 0, count: 0 };
    let searchTimer;
    let searchRequest;
    let matchingProducts = [];
    let searchedQuery = '';
    let customerSearchTimer;
    let customerSearchRequest;
    let matchingCustomers = [];
    let selectedCustomer = null;
    const pendingCartUpdates = new Set();

    async function updateCartItem(item, action, controls) {
        const productId = String(item.id);
        if (pendingCartUpdates.has(productId)) return;

        pendingCartUpdates.add(productId);
        controls.querySelectorAll('button').forEach((button) => {
            button.disabled = true;
        });

        try {
            if (action === 'remove') {
                const result = await sendRequest(`/cart/items/${item.id}`, 'DELETE');
                cart = result.cart;
            } else {
                const quantity = Number(item.quantity) + (action === 'increase' ? 1 : -1);
                const result = await sendRequest(`/cart/items/${item.id}`, 'PATCH', { quantity });
                cart = result.cart;
            }

            renderCart();
            message.hidden = true;
        } catch (error) {
            showMessage(error.message);
        } finally {
            pendingCartUpdates.delete(productId);
            if (cartItems.contains(controls)) {
                controls.querySelectorAll('button').forEach((button) => {
                    button.disabled = false;
                });
            }
        }
    }

    async function updateCartPrice(item, priceInput, submitButton) {
        const productId = String(item.id);
        if (pendingCartUpdates.has(productId)) return;

        if (!priceInput.reportValidity()) return;

        pendingCartUpdates.add(productId);
        priceInput.disabled = true;
        submitButton.disabled = true;

        try {
            const result = await sendRequest(`/cart/items/${item.id}`, 'PATCH', {
                price: Number(priceInput.value),
            });
            cart = result.cart;
            renderCart();
            message.hidden = true;
        } catch (error) {
            priceInput.value = item.price;
            showMessage(error.message);
        } finally {
            pendingCartUpdates.delete(productId);
            if (cartItems.contains(priceInput)) {
                priceInput.disabled = false;
                submitButton.disabled = false;
            }
        }
    }

    function showMessage(text, linkUrl = null) {
        message.replaceChildren(document.createTextNode(text));

        if (linkUrl) {
            const link = document.createElement('a');
            link.href = linkUrl;
            link.textContent = 'Add product';
            message.append(' ', link);
        }

        message.hidden = false;
    }

    function updatePayment() {
        const total = cart.total;
        const isCash = paymentMethodInput.value === 'cash';
        const received = cashInput.value === '' ? Number.NaN : Number(cashInput.value);
        const valid = cashInput.value !== '' && Number.isFinite(received) && received >= total;
        const isCredit = paymentMethodInput.value === 'credit';
        const isDelivery = fulfillmentTypeInput.value === 'delivery';
        const hasDeliveryAddress = Boolean(selectedCustomer?.address?.trim());

        cashPaymentFields.hidden = !isCash;
        cashInput.required = isCash;
        cashRequirementLabel.textContent = isCash ? 'required' : 'optional';
        creditHint.hidden = !isCredit;
        deliveryHint.hidden = !isDelivery || (Boolean(selectedCustomer) && hasDeliveryAddress);
        changeLabel.textContent = formatRupee(valid ? received - total : 0);
        checkoutButton.disabled = total <= 0
            || !salesManagerInput.value
            || (isCash && !valid)
            || (isCredit && !selectedCustomer)
            || (isDelivery && !hasDeliveryAddress);
    }

    function selectCustomer(customer) {
        selectedCustomer = customer;
        selectedCustomerName.textContent = customer?.name ?? '';
        selectedCustomerDetails.textContent = customer
            ? [customer.phone, customer.address].filter(Boolean).join(' · ')
            : '';
        selectedCustomerPanel.hidden = !customer;
        customerSearchInput.closest('.customer-search-wrap').hidden = Boolean(customer);
        newCustomerButton.hidden = Boolean(customer);
        newCustomerForm.hidden = true;
        customerSearchResults.replaceChildren();
        customerSearchResults.hidden = true;
        customerSearchInput.setAttribute('aria-expanded', 'false');
        updatePayment();
    }

    function renderCustomerSearchResults(customers) {
        customerSearchResults.replaceChildren();
        matchingCustomers = customers;

        if (customers.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'product-search-empty';
            empty.textContent = 'No regular customers found.';
            customerSearchResults.append(empty);
        } else {
            customers.forEach((customer, index) => {
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'product-search-option';
                option.dataset.customerIndex = index;
                const details = document.createElement('span');
                details.className = 'product-search-option-info';
                const name = document.createElement('strong');
                name.textContent = customer.name;
                const phone = document.createElement('span');
                phone.textContent = customer.phone || customer.address || 'Regular customer';
                details.append(name, phone);
                option.append(details);
                customerSearchResults.append(option);
            });
        }

        customerSearchResults.hidden = false;
        customerSearchInput.setAttribute('aria-expanded', 'true');
    }

    function renderCart() {
        cartItems.replaceChildren();
        emptyCart.hidden = cart.items.length > 0;
        countLabel.textContent = cart.count;
        totalLabel.textContent = formatRupee(cart.total);

        cart.items.forEach((item) => {
            const row = document.createElement('article');
            row.className = 'cart-item';

            const info = document.createElement('div');
            info.className = 'cart-item-info';
            const name = document.createElement('p');
            name.className = 'cart-item-name';
            name.textContent = item.name;
            const meta = document.createElement('span');
            meta.className = 'cart-item-meta';
            meta.textContent = `${item.barcode} · MRP ${formatRupee(item.mrp)} · ${formatRupee(item.price)} / ${item.quantity_unit}`;
            info.append(name, meta);

            const priceForm = document.createElement('form');
            priceForm.className = 'cart-price-form';
            const priceLabel = document.createElement('label');
            priceLabel.textContent = `Price this bill / ${item.quantity_unit}`;
            const priceInput = document.createElement('input');
            priceInput.type = 'number';
            priceInput.min = '1';
            priceInput.max = '999999999';
            priceInput.step = '1';
            priceInput.value = item.price;
            priceInput.required = true;
            priceInput.setAttribute('aria-label', `Custom price for ${item.name}`);
            priceLabel.append(priceInput);
            const setPriceButton = document.createElement('button');
            setPriceButton.type = 'submit';
            setPriceButton.className = 'cart-price-submit';
            setPriceButton.textContent = 'Set';
            priceForm.append(priceLabel, setPriceButton);
            priceForm.addEventListener('submit', (event) => {
                event.preventDefault();
                updateCartPrice(item, priceInput, setPriceButton);
            });
            if (item.price !== item.catalog_price) {
                const resetPriceButton = document.createElement('button');
                resetPriceButton.type = 'button';
                resetPriceButton.className = 'cart-price-reset';
                resetPriceButton.textContent = 'Reset';
                resetPriceButton.addEventListener('click', () => {
                    priceInput.value = item.catalog_price;
                    updateCartPrice(item, priceInput, setPriceButton);
                });
                priceForm.append(resetPriceButton);
            }
            info.append(priceForm);

            const side = document.createElement('div');
            side.className = 'cart-item-side';
            const controls = document.createElement('div');
            controls.className = 'quantity-control';
            controls.setAttribute('aria-label', `Quantity for ${item.name}`);

            const minus = document.createElement('button');
            minus.type = 'button';
            minus.dataset.action = item.quantity === 1 ? 'remove' : 'decrease';
            minus.dataset.id = item.id;
            minus.setAttribute('aria-label', item.quantity === 1 ? 'Remove item' : 'Decrease quantity');
            minus.textContent = '−';
            minus.addEventListener('click', () => {
                updateCartItem(item, item.quantity === 1 ? 'remove' : 'decrease', controls);
            });
            const quantity = document.createElement('span');
            quantity.textContent = `${item.quantity} ${item.quantity_unit}`;
            const plus = document.createElement('button');
            plus.type = 'button';
            plus.dataset.action = 'increase';
            plus.dataset.id = item.id;
            plus.setAttribute('aria-label', 'Increase quantity');
            plus.textContent = '+';
            plus.addEventListener('click', () => updateCartItem(item, 'increase', controls));
            controls.append(minus, quantity, plus);

            const price = document.createElement('strong');
            price.className = 'cart-item-price';
            price.textContent = formatRupee(item.line_total);
            side.append(controls, price);

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'remove-item';
            remove.dataset.action = 'remove';
            remove.dataset.id = item.id;
            remove.setAttribute('aria-label', `Remove ${item.name}`);
            remove.textContent = '×';
            remove.addEventListener('click', () => updateCartItem(item, 'remove', controls));
            side.append(remove);

            row.append(info, side);
            cartItems.append(row);
        });

        updatePayment();
    }

    function hideProductSearchResults() {
        productSearchResults.replaceChildren();
        productSearchResults.hidden = true;
        barcodeInput.setAttribute('aria-expanded', 'false');
        matchingProducts = [];
        searchedQuery = '';
    }

    function renderProductSearchResults(products, query) {
        productSearchResults.replaceChildren();
        matchingProducts = products;
        searchedQuery = query;

        if (products.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'product-search-empty';
            empty.textContent = 'No matching products found.';
            productSearchResults.append(empty);
        } else {
            products.forEach((product, index) => {
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'product-search-option';
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', 'false');
                option.dataset.index = index;

                const info = document.createElement('span');
                info.className = 'product-search-option-info';
                const name = document.createElement('strong');
                name.textContent = product.name;
                const barcode = document.createElement('span');
                barcode.textContent = product.barcode;
                info.append(name, barcode);

                const price = document.createElement('span');
                price.className = 'product-search-option-price';
                price.textContent = formatRupee(product.price);
                const stock = document.createElement('span');
                stock.className = 'product-search-option-stock';
                stock.textContent = `${product.stock} ${product.quantity_unit} left`;
                option.append(info, stock, price);
                productSearchResults.append(option);
            });
        }

        productSearchResults.hidden = false;
        barcodeInput.setAttribute('aria-expanded', 'true');
    }

    async function searchProducts(query) {
        searchRequest?.abort();
        searchRequest = new AbortController();
        const response = await fetch(`/cart/products?${new URLSearchParams({ q: query })}`, {
            headers: { Accept: 'application/json' },
            signal: searchRequest.signal,
        });
        const payload = await response.json();

        if (!response.ok) {
            throw new Error(payload.message || 'Could not search products. Please try again.');
        }

        return payload.products;
    }

    async function searchCustomers(query) {
        customerSearchRequest?.abort();
        customerSearchRequest = new AbortController();
        const response = await fetch(`/customers/search?${new URLSearchParams({ q: query })}`, {
            headers: { Accept: 'application/json' },
            signal: customerSearchRequest.signal,
        });
        const payload = await response.json();

        if (!response.ok) {
            throw new Error(payload.message || 'Could not search customers. Please try again.');
        }

        return payload.customers;
    }

    async function addProductByBarcode(barcode) {
        const result = await sendRequest('/cart/items', 'POST', { barcode });
        cart = result.cart;
        renderCart();
        showMessage(result.message);
        barcodeInput.value = '';
        hideProductSearchResults();
        barcodeInput.focus();
    }

    barcodeInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchRequest?.abort();
        const query = barcodeInput.value.trim();

        if (query.length < 2) {
            hideProductSearchResults();
            return;
        }

        searchTimer = setTimeout(async () => {
            try {
                const products = await searchProducts(query);
                if (barcodeInput.value.trim() === query) {
                    renderProductSearchResults(products, query);
                }
            } catch (error) {
                if (error.name !== 'AbortError') showMessage(error.message);
            }
        }, 180);
    });

    scanForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const barcode = barcodeInput.value.trim();

        if (!barcode) return;

        try {
            clearTimeout(searchTimer);
            searchRequest?.abort();

            if (barcode.length < 2) {
                await addProductByBarcode(barcode);
                return;
            }

            let products = matchingProducts;
            if (searchedQuery !== barcode) {
                products = await searchProducts(barcode);
            }

            const exactBarcodeMatch = products.find((product) => product.barcode.toLowerCase() === barcode.toLowerCase());
            const exactNameMatches = products.filter((product) => product.name.toLowerCase() === barcode.toLowerCase());

            if (exactBarcodeMatch || exactNameMatches.length === 1) {
                await addProductByBarcode((exactBarcodeMatch ?? exactNameMatches[0]).barcode);
                return;
            }

            if (products.length > 0) {
                renderProductSearchResults(products, barcode);
                showMessage('Select the product you want to add.');
                return;
            }

            await addProductByBarcode(barcode);
        } catch (error) {
            showMessage(error.message, error.addProductUrl);
            barcodeInput.select();
        }
    });

    productSearchResults.addEventListener('click', async (event) => {
        const option = event.target.closest('button[data-index]');
        if (!option) return;

        const product = matchingProducts[Number(option.dataset.index)];
        if (!product) return;

        try {
            await addProductByBarcode(product.barcode);
        } catch (error) {
            showMessage(error.message, error.addProductUrl);
        }
    });

    barcodeInput.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' && !productSearchResults.hidden) {
            event.preventDefault();
            productSearchResults.querySelector('button')?.focus();
        } else if (event.key === 'Escape') {
            hideProductSearchResults();
        }
    });

    productSearchResults.addEventListener('keydown', (event) => {
        const options = [...productSearchResults.querySelectorAll('button[data-index]')];
        const currentIndex = options.indexOf(document.activeElement);
        if (event.key === 'ArrowDown' && currentIndex < options.length - 1) {
            event.preventDefault();
            options[currentIndex + 1].focus();
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            if (currentIndex <= 0) barcodeInput.focus();
            else options[currentIndex - 1].focus();
        } else if (event.key === 'Escape') {
            hideProductSearchResults();
            barcodeInput.focus();
        }
    });

    customerSearchInput.addEventListener('input', () => {
        clearTimeout(customerSearchTimer);
        customerSearchRequest?.abort();
        const query = customerSearchInput.value.trim();

        if (query.length < 2) {
            customerSearchResults.replaceChildren();
            customerSearchResults.hidden = true;
            customerSearchInput.setAttribute('aria-expanded', 'false');
            return;
        }

        customerSearchTimer = setTimeout(async () => {
            try {
                const customers = await searchCustomers(query);
                if (customerSearchInput.value.trim() === query) {
                    renderCustomerSearchResults(customers);
                }
            } catch (error) {
                if (error.name !== 'AbortError') {
                    customerMessage.textContent = error.message;
                    customerMessage.hidden = false;
                }
            }
        }, 180);
    });

    customerSearchResults.addEventListener('click', (event) => {
        const option = event.target.closest('button[data-customer-index]');
        if (!option) return;

        const customer = matchingCustomers[Number(option.dataset.customerIndex)];
        if (customer) selectCustomer(customer);
    });

    clearCustomerButton.addEventListener('click', () => {
        selectCustomer(null);
        customerSearchInput.value = '';
        customerSearchInput.focus();
    });

    newCustomerButton.addEventListener('click', () => {
        newCustomerForm.hidden = false;
        newCustomerButton.hidden = true;
        newCustomerForm.querySelector('input[name="name"]').focus();
    });

    document.querySelector('#cancel-new-customer').addEventListener('click', () => {
        newCustomerForm.reset();
        newCustomerForm.hidden = true;
        newCustomerButton.hidden = false;
        customerMessage.hidden = true;
    });

    newCustomerForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        customerMessage.hidden = true;
        const customerFormData = new FormData(newCustomerForm);
        const values = Object.fromEntries(customerFormData.entries());

        try {
            const result = await sendRequest('/customers', 'POST', values);
            newCustomerForm.reset();
            selectCustomer(result.customer);
        } catch (error) {
            customerMessage.textContent = error.message;
            customerMessage.hidden = false;
        }
    });

    cashInput.addEventListener('input', updatePayment);
    fulfillmentTypeInput.addEventListener('change', updatePayment);
    paymentMethodInput.addEventListener('change', updatePayment);
    salesManagerInput.addEventListener('change', updatePayment);

    function prepareReceipt(receipt) {
        document.querySelector('#receipt-number').textContent = receipt.receipt_number;
        document.querySelector('#receipt-date').textContent = new Intl.DateTimeFormat('en-IN', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
            timeZone: sellPageClock?.dataset.timezone ?? 'Asia/Kolkata',
        }).format(new Date(receipt.sold_at));
        document.querySelector('#receipt-payment-method').textContent = {
            cash: 'Cash',
            gpay: 'GPay',
            bank_transfer: 'Bank transfer',
            credit: 'Credit / pay later',
        }[receipt.payment_method];
        document.querySelector('#receipt-sales-manager').textContent = receipt.sales_manager_name;
        document.querySelector('#receipt-fulfillment-type').textContent = receipt.fulfillment_type === 'delivery' ? 'Delivery' : 'Walk-in';
        document.querySelector('#receipt-total').textContent = formatRupee(receipt.total);
        document.querySelector('#receipt-total-label').textContent = receipt.payment_method === 'credit' ? 'Sale total' : 'Total';
        const creditDetails = document.querySelector('#receipt-credit-details');
        creditDetails.hidden = receipt.payment_method !== 'credit';
        document.querySelector('#receipt-balance-due').textContent = formatRupee(receipt.balance_due);

        const customerDetails = document.querySelector('#receipt-customer-details');
        const customerFields = [
            ['receipt-customer-name', receipt.customer_name],
            ['receipt-customer-phone', receipt.customer_phone],
            ['receipt-customer-address', receipt.customer_address],
        ];
        let hasCustomerDetails = false;
        customerFields.forEach(([id, value]) => {
            const element = document.querySelector(`#${id}`);
            element.textContent = value ?? '';
            element.hidden = !value;
            hasCustomerDetails ||= Boolean(value);
        });
        customerDetails.hidden = !hasCustomerDetails;

        const receiptItems = document.querySelector('#receipt-items');
        receiptItems.replaceChildren();
        receipt.items.forEach((item) => {
            const row = document.createElement('tr');
            const productCell = document.createElement('td');
            const productName = document.createElement('strong');
            productName.textContent = item.name;
            productCell.append(productName);

            const mrpCell = document.createElement('td');
            mrpCell.textContent = formatRupee(item.mrp);

            const quantityCell = document.createElement('td');
            const quantity = document.createElement('strong');
            quantity.textContent = item.quantity;
            quantityCell.append(quantity);

            const salePriceCell = document.createElement('td');
            salePriceCell.textContent = formatRupee(item.unit_price);

            const totalCell = document.createElement('td');
            totalCell.textContent = formatRupee(item.line_total);

            row.append(productCell, mrpCell, quantityCell, salePriceCell, totalCell);
            receiptItems.append(row);
        });

        const cashDetails = document.querySelector('#receipt-cash-details');
        cashDetails.hidden = receipt.payment_method !== 'cash';
        document.querySelector('#receipt-cash-received').textContent = formatRupee(receipt.cash_received);
        document.querySelector('#receipt-change-due').textContent = formatRupee(receipt.change_due);
    }

    printReceiptButton.addEventListener('click', () => window.print());

    checkoutButton.addEventListener('click', async () => {
        if (!salesManagerInput.checkValidity()) {
            salesManagerInput.reportValidity();
            return;
        }

        if (paymentMethodInput.value === 'credit' && !selectedCustomer) {
            showMessage('Select or add a regular customer before recording a credit sale.');
            return;
        }

        if (fulfillmentTypeInput.value === 'delivery' && !selectedCustomer?.address?.trim()) {
            showMessage('Delivery requires selecting a customer with a saved address.');
            return;
        }

        if (paymentMethodInput.value === 'cash' && cashInput.value.trim() === '') {
            cashInput.reportValidity();
            showMessage('Enter the amount received in cash.');
            return;
        }

        const values = {
            fulfillment_type: fulfillmentTypeInput.value,
            payment_method: paymentMethodInput.value,
            sales_manager_id: salesManagerInput.value,
        };
        if (selectedCustomer) values.customer_id = selectedCustomer.id;
        if (paymentMethodInput.value === 'cash') {
            values.cash_received = cashInput.value;
        }
        checkoutButton.disabled = true;
        checkoutButton.querySelector('span:first-child').textContent = 'Saving sale...';

        try {
            const result = await sendRequest('/sales', 'POST', values);
            prepareReceipt(result);
            printReceiptButton.hidden = false;
            cart = { items: [], total: 0, count: 0 };
            cashInput.value = '';
            fulfillmentTypeInput.value = 'walk_in';
            paymentMethodInput.value = 'cash';
            selectCustomer(null);
            customerSearchInput.value = '';
            renderCart();
            const changeMessage = result.payment_method === 'cash'
                ? ` Change: ${formatRupee(result.change_due)}.`
                : '';
            showMessage(`${result.message} Receipt ${result.receipt_number}.${changeMessage}`);
            window.print();
        } catch (error) {
            showMessage(error.message);
        } finally {
            checkoutButton.querySelector('span:first-child').textContent = 'Complete sale';
            updatePayment();
            barcodeInput.focus();
        }
    });

    renderCart();
    barcodeInput.focus();
}

const saleEditForm = document.querySelector('#sale-edit-form');

if (saleEditForm) {
    const saleItemsList = saleEditForm.querySelector('#sale-items-list');
    const saleItemTemplate = saleEditForm.querySelector('#sale-item-template');
    const fulfillmentTypeInput = saleEditForm.querySelector('#sale-fulfillment-type');
    const customerInput = saleEditForm.querySelector('#sale-customer');
    const paymentMethodInput = saleEditForm.querySelector('#sale-payment-method');
    const cashFields = saleEditForm.querySelector('#sale-cash-fields');
    const cashReceivedInput = saleEditForm.querySelector('#sale-cash-received');

    const updatePaymentFields = () => {
        const isCash = paymentMethodInput.value === 'cash';
        cashFields.hidden = !isCash;
        cashReceivedInput.disabled = !isCash;
        cashReceivedInput.required = isCash;
        customerInput.required = paymentMethodInput.value === 'credit' || fulfillmentTypeInput.value === 'delivery';
    };

    const updateRemoveButtons = () => {
        const removeButtons = saleItemsList.querySelectorAll('.remove-sale-item');
        removeButtons.forEach((button) => {
            button.disabled = removeButtons.length === 1;
        });
    };

    const updatePriceLabel = (productSelect) => {
        const priceLabel = productSelect.closest('.form-field').querySelector('[data-price-label]');
        const selectedOption = productSelect.selectedOptions[0];
        const unitPrice = selectedOption?.dataset.unitPrice;

        priceLabel.textContent = unitPrice
            ? `${selectedOption.dataset.priceLabel}: ₹${Number(unitPrice).toLocaleString('en-IN')}`
            : 'Select a product to see its price.';
    };

    saleEditForm.querySelector('#add-sale-item').addEventListener('click', () => {
        const index = Number.parseInt(saleItemsList.dataset.nextIndex, 10) || 0;
        saleItemsList.dataset.nextIndex = String(index + 1);
        const newRow = saleItemTemplate.content.cloneNode(true);

        newRow.querySelectorAll('[id], [name], [for]').forEach((element) => {
            ['id', 'name', 'for'].forEach((attribute) => {
                const value = element.getAttribute(attribute);
                if (value) {
                    element.setAttribute(attribute, value.replaceAll('__INDEX__', String(index)));
                }
            });
        });

        saleItemsList.append(newRow);
        updateRemoveButtons();
    });

    saleItemsList.addEventListener('change', (event) => {
        if (event.target.matches('select[name^="items["]')) {
            updatePriceLabel(event.target);
        }
    });

    saleItemsList.addEventListener('click', (event) => {
        const removeButton = event.target.closest('.remove-sale-item');
        if (!removeButton || removeButton.disabled) return;

        removeButton.closest('.sale-item-row').remove();
        updateRemoveButtons();
    });

    fulfillmentTypeInput.addEventListener('change', updatePaymentFields);
    paymentMethodInput.addEventListener('change', updatePaymentFields);
    saleItemsList.querySelectorAll('select[name^="items["]').forEach(updatePriceLabel);
    updateRemoveButtons();
    updatePaymentFields();
}

const productForm = document.querySelector('#product-form');

if (productForm) {
    const barcodeInput = document.querySelector('#product-barcode');
    const nameInput = document.querySelector('#product-name');
    const brandInput = document.querySelector('#product-brand');
    const mrpInput = document.querySelector('#product-mrp');
    const priceInput = document.querySelector('#product-price');
    const stockInput = document.querySelector('#product-stock');
    const quantityUnitInput = document.querySelector('#product-quantity-unit');
    const lowStockThresholdInput = document.querySelector('#product-low-stock-threshold');
    const unitCostInput = document.querySelector('#product-unit-cost');
    const modeNote = document.querySelector('#product-mode');
    const submitButton = productForm.querySelector('button[type="submit"]');
    let lookupInProgress = false;

    async function lookupBarcode() {
        const barcode = barcodeInput.value.trim();
        if (!barcode || lookupInProgress) return;

        lookupInProgress = true;
        modeNote.className = 'mode-note';
        modeNote.textContent = 'Checking barcode...';

        try {
            const query = new URLSearchParams({ barcode });
            const response = await fetch(`/products/lookup?${query}`, {
                headers: { Accept: 'application/json' },
            });
            const result = await response.json();

            if (response.status === 404) {
                nameInput.value = '';
                brandInput.value = '';
                mrpInput.value = '';
                priceInput.value = '';
                stockInput.value = '';
                quantityUnitInput.value = 'piece';
                lowStockThresholdInput.value = '5';
                if (unitCostInput) unitCostInput.value = '';
                modeNote.textContent = result.message;
                nameInput.focus();
                return;
            }

            if (!response.ok) {
                throw new Error(result.message || 'Could not check this barcode.');
            }

            barcodeInput.value = result.product.barcode;
            nameInput.value = result.product.name;
            brandInput.value = result.product.brand ?? '';
            mrpInput.value = result.product.mrp;
            priceInput.value = result.product.price;
            stockInput.value = result.product.stock;
            quantityUnitInput.value = result.product.quantity_unit;
            lowStockThresholdInput.value = result.product.low_stock_threshold;
            if (unitCostInput) unitCostInput.value = result.product.unit_cost ?? '';
            modeNote.className = 'mode-note found';
            modeNote.textContent = 'Product found. Change its details, then save to update it.';
            submitButton.textContent = 'Update product';
            nameInput.focus();
        } catch (error) {
            modeNote.className = 'mode-note error';
            modeNote.textContent = error.message;
        } finally {
            lookupInProgress = false;
        }
    }
    barcodeInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            lookupBarcode();
        }
    });

    barcodeInput.addEventListener('input', () => {
        submitButton.textContent = 'Save product';
        modeNote.className = 'mode-note';
        modeNote.textContent = 'Scan a barcode to start a new product.';
    });

    if (barcodeInput.value.trim() && !nameInput.value && !priceInput.value && !stockInput.value) {
        lookupBarcode();
    } else {
        barcodeInput.focus();
    }
}

function setupProfitChart() {
    const profitChart = document.querySelector('#profit-chart');
    const profitRange = document.querySelector('#profit-range');

    if (profitRange) {
        profitRange.addEventListener('change', () => profitRange.form.requestSubmit());
    }

    if (profitChart && Array.isArray(window.profitChartData)) {
        const svgNamespace = 'http://www.w3.org/2000/svg';
        const chartData = window.profitChartData;
        const dimensions = { width: 1000, height: 340, left: 82, right: 24, top: 24, bottom: 52 };
        const plotWidth = dimensions.width - dimensions.left - dimensions.right;
        const plotHeight = dimensions.height - dimensions.top - dimensions.bottom;
        const createSvgElement = (name, attributes = {}) => {
            const element = document.createElementNS(svgNamespace, name);
            Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, value));
            return element;
        };
        const rupeeAxisLabel = (value) => {
            const absoluteValue = Math.abs(value);
            if (absoluteValue >= 1_000_000_000) return `₹${(value / 1_000_000_000).toLocaleString('en-IN', { maximumFractionDigits: 1 })} M`;
            if (absoluteValue >= 1_000_000) return `₹${(value / 1_000_000).toLocaleString('en-IN', { maximumFractionDigits: 1 })} jt`;
            if (absoluteValue >= 1_000) return `₹${(value / 1_000).toLocaleString('en-IN', { maximumFractionDigits: 0 })} rb`;
            return `₹${Math.round(value).toLocaleString('en-IN')}`;
        };

        profitChart.replaceChildren();

        const availableValues = chartData.flatMap((day) => [day.gross, day.net])
            .filter((value) => Number.isFinite(value));

        if (availableValues.length === 0) {
            const message = createSvgElement('text', {
                x: dimensions.width / 2,
                y: dimensions.height / 2,
                'text-anchor': 'middle',
                class: 'chart-empty-label',
            });
            message.textContent = 'Profit unavailable until product costs are recorded';
            profitChart.append(message);
        } else {
            const minValue = Math.min(0, ...availableValues);
            const maxValue = Math.max(0, ...availableValues);
            const rawSpan = maxValue - minValue;
            const padding = rawSpan === 0 ? Math.max(Math.abs(maxValue) * 0.15, 1000) : rawSpan * 0.14;
            const lowerBound = minValue < 0 ? minValue - padding : rawSpan === 0 ? -padding : 0;
            const upperBound = maxValue > 0 ? maxValue + padding : rawSpan === 0 ? padding : 0;
            const span = upperBound - lowerBound || 1;
            const yFor = (value) => dimensions.top + ((upperBound - value) / span) * plotHeight;
            const xFor = (index) => dimensions.left + (chartData.length === 1 ? plotWidth / 2 : (index / (chartData.length - 1)) * plotWidth);
            const zeroY = yFor(0);

            const definitions = createSvgElement('defs');
            const gradient = createSvgElement('linearGradient', { id: 'gross-profit-fill', x1: '0', y1: '0', x2: '0', y2: '1' });
            gradient.append(
                createSvgElement('stop', { offset: '0%', 'stop-color': '#168052', 'stop-opacity': '.19' }),
                createSvgElement('stop', { offset: '100%', 'stop-color': '#168052', 'stop-opacity': '.01' }),
            );
            definitions.append(gradient);
            profitChart.append(definitions);

            for (let tick = 0; tick <= 4; tick += 1) {
                const value = upperBound - (span * tick) / 4;
                const y = yFor(value);
                profitChart.append(
                    createSvgElement('line', {
                        x1: dimensions.left,
                        x2: dimensions.width - dimensions.right,
                        y1: y,
                        y2: y,
                        class: value === 0 ? 'chart-zero-line' : 'chart-grid-line',
                    }),
                );
                const label = createSvgElement('text', {
                    x: dimensions.left - 13,
                    y: y + 4,
                    'text-anchor': 'end',
                    class: 'chart-axis-label',
                });
                label.textContent = rupeeAxisLabel(value);
                profitChart.append(label);
            }

            const labelStep = Math.max(1, Math.ceil((chartData.length - 1) / 5));
            chartData.forEach((day, index) => {
                if (index % labelStep !== 0 && index !== chartData.length - 1) return;
                const date = new Date(`${day.date}T00:00:00`);
                const label = createSvgElement('text', {
                    x: xFor(index),
                    y: dimensions.height - 16,
                    'text-anchor': index === 0 ? 'start' : index === chartData.length - 1 ? 'end' : 'middle',
                    class: 'chart-axis-label chart-date-label',
                });
                label.textContent = date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
                profitChart.append(label);
            });

            const pointsFor = (key) => chartData.map((day, index) => ({
                x: xFor(index),
                y: Number.isFinite(day[key]) ? yFor(day[key]) : null,
                value: day[key],
                date: day.date,
            }));
            const segmentsFor = (points) => {
                const segments = [];
                points.forEach((point) => {
                    if (point.y === null) {
                        if (segments.at(-1)?.length) segments.push([]);
                        return;
                    }
                    if (!segments.length || !segments.at(-1).length) segments.push([]);
                    segments.at(-1).push(point);
                });
                return segments.filter((segment) => segment.length);
            };
            const grossPoints = pointsFor('gross');
            const netPoints = pointsFor('net');

            segmentsFor(grossPoints).forEach((segment) => {
                const first = segment[0];
                const last = segment.at(-1);
                const line = segment.map((point, index) => `${index === 0 ? 'M' : 'L'}${point.x},${point.y}`).join(' ');
                const area = `${line} L${last.x},${zeroY} L${first.x},${zeroY} Z`;
                profitChart.append(createSvgElement('path', { d: area, class: 'chart-gross-area' }));
            });

            [
                { key: 'gross', points: grossPoints, lineClass: 'chart-gross-line', pointClass: 'chart-gross-point', title: 'Gross profit' },
                { key: 'net', points: netPoints, lineClass: 'chart-net-line', pointClass: 'chart-net-point', title: 'Net profit' },
            ].forEach((series) => {
                segmentsFor(series.points).forEach((segment) => {
                    const path = segment.map((point, index) => `${index === 0 ? 'M' : 'L'}${point.x},${point.y}`).join(' ');
                    profitChart.append(createSvgElement('path', { d: path, class: series.lineClass }));
                });

                series.points.forEach((point) => {
                    if (point.y === null) return;
                    const circle = createSvgElement('circle', {
                        cx: point.x,
                        cy: point.y,
                        r: chartData.length > 45 ? 2.4 : 3.5,
                        class: series.pointClass,
                    });
                    const title = createSvgElement('title');
                    title.textContent = `${series.title} · ${new Date(`${point.date}T00:00:00`).toLocaleDateString('id-ID')} · ${formatRupee(point.value)}`;
                    circle.append(title);
                    profitChart.append(circle);
                });
            });
        }
    }
}

setupProfitChart();
