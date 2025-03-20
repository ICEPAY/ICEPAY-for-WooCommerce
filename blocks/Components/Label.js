export const Label = ({id, icon, name, description}) => {
    let icons = [icon];
    if (id === 'icepay-card') {
        icons = [
            icon.replace("card", 'visa'),
            icon.replace("card", 'mastercard'),
        ];
    }

    return (
        <div className="icepay-label">
            <span className="icepay-name">{name}</span>
            <span className="icepay-description" style={{}}>{description}</span>
            <div className="icepay-icon-container">
                {icons.filter(icon => (icon !== null) && (icon !== '')).map((icon) => (
                    <img className="icepay-icon" src={icon} alt={`Payment Method ${name} icon`}/>
                ))}
            </div>
        </div>
    );
};