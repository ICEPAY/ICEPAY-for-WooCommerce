import {registerPaymentMethod} from '@woocommerce/blocks-registry';
import {PaymentMethod} from './PaymentMethods/PaymentMethod.js';
import {Label} from './Components/Label.js';

const stylesheet = new CSSStyleSheet();
stylesheet.replaceSync(`
	.wc-block-components-radio-control__label .icepay-label {
		width: 100%;
		padding-right: 3.5rem;
	}

	.wc-block-components-radio-control__label .icepay-label .icepay-description {
		padding-left: 1rem;
		font-size: .875rem;
    	line-height: 1.2;
	}

	.wc-block-components-radio-control__label .icepay-label .icepay-icon-container {
		display: flex;
		justify-content: flex-end;
		float: right;
		gap: 0.5rem;
	}

	.wc-block-components-radio-control__label .icepay-label .icepay-icon-container img {
		border-radius: 0.25rem;
		border-width: 1px;
		border-color: rgb(226 232 240);
		border-style: solid;
		padding: 0.125rem;
		object-fit: contain;
		width: 2rem;
		background-color: white;
		object-position: center;
	}
`);

document.adoptedStyleSheets = [stylesheet];
icepay.paymentMethods.forEach((paymentMethod) => {
    registerPaymentMethod({
        name: paymentMethod.id,
        label: <Label id={paymentMethod.id} icon={paymentMethod.icon} name={paymentMethod.title}
                      description={paymentMethod.description}/>,
        edit: <div/>,
        canMakePayment: () => true,
        ariaLabel: paymentMethod.title,
        content: <PaymentMethod paymentMethod={paymentMethod}/>,
    });
});