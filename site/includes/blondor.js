
const getWidth = (elementId) =>{
    const element = document.getElementById(elementId);
    const width = element.offsetWidth;

    return width;
}

const getHeight = (elementId) =>{
    const element = document.getElementById(elementId);
    const height = element.offsetHeight;

    return height;
}

const setHeight = (elementId, newHeight) => {
    const element = document.getElementById(elementId);
    element.style.height = `${newHeight}px`;
};

const updateImages = () =>{
    if ($(window).width() <= 320) {
        $('#image1').attr('src', './includes/PNG/imagen2_xsmall.png').attr('class','img-fluid');
        $('#image2').attr('src', './includes/PNG/imagen3_xsmall.png').attr('class','img-fluid');
        $('#image3').attr('src', './includes/PNG/imagen4_xsmall.png').attr('class','img-fluid');
    } 
    else if($(window).width() >= 375 && $(window).width() < 768){
        $('#image1').attr('src', './includes/PNG/imagen2_small.png').attr('class','img-fluid');
        $('#image2').attr('src', './includes/PNG/imagen3_small.png').attr('class','img-fluid');
        $('#image3').attr('src', './includes/PNG/imagen4_small.png').attr('class','img-fluid');
    }
    else{
        $('#image1').attr('src', './includes/PNG/imagen2.png');
        $('#image2').attr('src', './includes/PNG/imagen3.png');
        $('#image3').attr('src', './includes/PNG/imagen4.png');  
    }
}

const enviaForm = (element) =>{
    
    var xhr = new XMLHttpRequest();
    var data = {
        nombre : document.getElementById('nombre-form').value,
        correo : document.getElementById('correo-form').value,
        telefono : document.getElementById('telefono-form').value,
        mensaje : document.getElementById('mensaje-form').value,
    };
    // Convertir el objeto data en una cadena de consulta (query string)
    var params = new URLSearchParams(data).toString();

    xhr.open("GET", "./includes/mail.php?"+params, true);
    xhr.onreadystatechange = function() {
        // Verificar si la solicitud se completó (estado 4) y si fue exitosa (estado 200)
        if (xhr.readyState === 4 && xhr.status === 200) {
            // Parsear la respuesta JSON
            var respuesta = JSON.parse(xhr.responseText);
            // Mostrar la respuesta en la consola
            if(respuesta.status === -1){
                Swal.fire({
                    title: 'Error!',
                    text: respuesta.message,
                    icon: 'error',
                    confirmButtonText: 'OK'
                  })
            }
            else{
                Swal.fire({
                    position: "center",
                    icon: "success",
                    title: respuesta.message,
                    showConfirmButton: false,
                    timer: 1500
                  });
            }

            
            
        }
        else{
            Swal.fire({
                icon: "error",
                title: "Oops...",
                text: "Algo Salió Mal :C"
              });
        }
    };
    xhr.send();
}

document.addEventListener("DOMContentLoaded", function() {
    console.log("ancho_mensaje_container",getWidth("mensaje-container"));
    console.log("largo_mensaje_container",getHeight("mensaje-container"));
    console.log("ancho",getWidth("nosotros_row"));
    console.log("largo",getHeight("nosotros_row"));

    setHeight("nosotros_row", getHeight("mensaje-container") - 120); // Ajusta el alto del div a 300px
});


document.addEventListener('DOMContentLoaded', updateImages);
window.addEventListener('resize', updateImages);

document.getElementById('enviar-form').addEventListener('click',function(event){
    event.preventDefault(); // Evita que el formulario se envíe
    enviaForm('enviar-form');
    return false;
});

